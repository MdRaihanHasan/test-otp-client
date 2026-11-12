<?php

namespace Bixcel\TestOtp;

use Bixcel\TestOtp\Data\CheckResult;
use Bixcel\TestOtp\Data\OtpDispatch;
use Bixcel\TestOtp\Exceptions\SmsDeliveryBlockedException;
use Bixcel\TestOtp\Exceptions\TestAccountRestrictedException;
use Bixcel\TestOtp\Exceptions\TestMobileNotAllowedException;
use Closure;
use InvalidArgumentException;

/**
 * Routes OTP delivery: registered test mobile IDs -> central virtual inbox,
 * every other number -> your existing SMS flow (unchanged).
 */
class OtpRouter
{
    public function __construct(
        private readonly TestOtpClient $client,
        private readonly string $mode = 'platform',
    ) {}

    public function client(): TestOtpClient
    {
        return $this->client;
    }

    public function check(string $mobile): CheckResult
    {
        return $this->client->check($mobile);
    }

    public function isTestMobile(string $mobile): bool
    {
        return $this->client->check($mobile)->isTest;
    }

    /**
     * Send an OTP.
     *
     * @param  Closure(string $mobile): mixed  $smsSender  Your existing SMS delivery (Twilio, etc.). Only called for real numbers.
     * @param  array{otp?: string, purpose?: string, account?: string, application?: string, message?: string, ttl_minutes?: int, metadata?: array}  $context
     *
     * @throws TestMobileNotAllowedException
     */
    public function send(string $mobile, Closure $smsSender, array $context = []): OtpDispatch
    {
        $check = $this->client->check($mobile);

        if (! $check->isTest) {
            return new OtpDispatch('sms', providerResult: $smsSender($mobile));
        }

        if (! $check->allowed) {
            throw new TestMobileNotAllowedException($check->reason);
        }

        if ($this->mode === 'platform' && empty($context['otp'])) {
            throw new InvalidArgumentException('In "platform" mode pass the OTP your app generated as $context[\'otp\'].');
        }
        if ($this->mode === 'hub') {
            unset($context['otp']);
        }

        $response = $this->client->generate($mobile, $context);

        return new OtpDispatch('virtual_inbox', $response['message_id'] ?? null, hubManaged: $this->mode === 'hub');
    }

    /**
     * Verify an OTP. In "platform" mode (or for real numbers) your existing verifier runs.
     * In "hub" mode test IDs are verified by the hub.
     *
     * @param  Closure(string $mobile, string $otp): bool  $localVerifier
     */
    public function verify(string $mobile, string $otp, Closure $localVerifier, ?string $purpose = null): bool
    {
        if ($this->mode === 'hub' && $this->client->check($mobile)->isTest) {
            return (bool) ($this->client->verify($mobile, $otp, $purpose)['valid'] ?? false);
        }

        return (bool) $localVerifier($mobile, $otp);
    }

    /**
     * Defence in depth: call immediately before any SMS-provider API call.
     *
     * @throws SmsDeliveryBlockedException
     */
    public function assertSmsAllowed(string $mobile): void
    {
        if ($this->client->check($mobile)->isTest) {
            throw new SmsDeliveryBlockedException('Refusing to send SMS to test mobile ID '.TestOtpClient::normalize($mobile).'.');
        }
    }

    /**
     * Block real transactions / payments / external submissions for test accounts.
     *
     * @throws TestAccountRestrictedException
     */
    public function assertNotTestAccount(?string $mobile, string $action = 'this action'): void
    {
        if ($mobile !== null && $mobile !== '' && $this->client->check($mobile)->isTest) {
            throw new TestAccountRestrictedException($action);
        }
    }
}
