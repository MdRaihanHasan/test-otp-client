<?php

namespace Bixcel\TestOtp;

use Bixcel\TestOtp\Data\CheckResult;
use Bixcel\TestOtp\Exceptions\HubUnavailableException;
use Bixcel\TestOtp\Exceptions\TestMobileNotAllowedException;
use Bixcel\TestOtp\Exceptions\TestOtpException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * Thin HTTP client for the Central Test OTP API.
 */
class TestOtpClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly Cache $cache,
        private readonly array $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false) && ! empty($this->config['base_url']) && ! empty($this->config['token']);
    }

    public static function normalize(string $mobile): string
    {
        return preg_replace('/[\s\-().]/', '', trim($mobile)) ?? '';
    }

    /** Cheap local pre-filter: only numbers in test-ID format can possibly be test accounts. */
    public function isCandidate(string $mobile): bool
    {
        return $this->enabled() && (bool) preg_match($this->config['candidate_pattern'] ?? '/^90\d{8}$/', self::normalize($mobile));
    }

    /**
     * Ask the hub whether the number is a registered test mobile ID.
     * Non-candidate numbers return "normal" without any network call.
     *
     * @throws HubUnavailableException when the hub is unreachable and fail_closed is enabled
     */
    public function check(string $mobile): CheckResult
    {
        $mobile = self::normalize($mobile);

        if (! $this->isCandidate($mobile)) {
            return CheckResult::normal();
        }

        $ttl = (int) ($this->config['cache_ttl'] ?? 0);
        $cacheKey = 'test-otp:check:'.($this->config['platform'] ?? '').':'.$mobile;

        if ($ttl > 0 && is_array($cached = $this->cache->get($cacheKey))) {
            return CheckResult::fromResponse($cached);
        }

        try {
            $data = $this->post('/api/v1/test-mobile/check', ['mobile' => $mobile])->json();
        } catch (HubUnavailableException $e) {
            if ($this->config['fail_closed'] ?? true) {
                throw $e;
            }

            return CheckResult::normal();
        }

        if ($ttl > 0) {
            $this->cache->put($cacheKey, $data, $ttl);
        }

        return CheckResult::fromResponse($data);
    }

    /**
     * Deposit an OTP in the virtual inbox. Pass $context['otp'] to store your own code
     * ("platform" mode); omit it to let the hub generate one ("hub" mode).
     *
     * @param  array{purpose?: string, account?: string, application?: string, otp?: string, message?: string, ttl_minutes?: int, metadata?: array}  $context
     * @return array<string, mixed>
     */
    public function generate(string $mobile, array $context = []): array
    {
        return $this->post('/api/v1/test-otp/generate', ['mobile' => self::normalize($mobile)] + array_filter($context, fn ($v) => $v !== null))->json();
    }

    /** @return array{valid: bool, reason?: string} */
    public function verify(string $mobile, string $otp, ?string $purpose = null): array
    {
        return $this->post('/api/v1/test-otp/verify', array_filter([
            'mobile' => self::normalize($mobile),
            'otp' => $otp,
            'purpose' => $purpose,
        ]))->json();
    }

    /**
     * Latest OTP for a test ID — for automated E2E suites only (credential needs otp.retrieve).
     *
     * @return array<string, mixed>|null
     */
    public function retrieve(string $mobile, ?string $purpose = null): ?array
    {
        try {
            return $this->post('/api/v1/test-otp/retrieve', array_filter([
                'mobile' => self::normalize($mobile),
                'purpose' => $purpose,
            ]))->json();
        } catch (TestOtpException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    public function forget(string $mobile): void
    {
        $this->cache->forget('test-otp:check:'.($this->config['platform'] ?? '').':'.self::normalize($mobile));
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim((string) $this->config['base_url'], '/'))
            ->withToken((string) $this->config['token'])
            ->acceptJson()
            ->asJson()
            ->timeout((float) ($this->config['timeout'] ?? 3))
            ->retry((int) ($this->config['retries'] ?? 0) + 1, 200, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()), throw: false);
    }

    private function post(string $uri, array $payload): Response
    {
        if (! $this->enabled()) {
            throw new HubUnavailableException('Test OTP hub is not configured.');
        }

        $payload['platform'] = $this->config['platform'] ?? null;

        try {
            $response = $this->request()->post($uri, array_filter($payload, fn ($v) => $v !== null));
        } catch (ConnectionException $e) {
            throw new HubUnavailableException('Test OTP hub is unreachable: '.$e->getMessage(), 0, $e);
        }

        if ($response->serverError()) {
            throw new HubUnavailableException('Test OTP hub error (HTTP '.$response->status().').', $response->status());
        }

        if ($response->status() === 403 && $response->json('error') === 'test_mobile_not_allowed') {
            throw new TestMobileNotAllowedException($response->json('reason'));
        }

        if ($response->failed()) {
            throw new TestOtpException(
                'Test OTP hub rejected the request: '.($response->json('error') ?? 'HTTP '.$response->status()),
                $response->status(),
            );
        }

        return $response;
    }
}
