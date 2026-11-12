<?php

namespace Bixcel\TestOtp\Http\Middleware;

use Bixcel\TestOtp\Exceptions\HubUnavailableException;
use Bixcel\TestOtp\OtpRouter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply to payment, transaction and external-submission routes:
 *   Route::post('/payments', ...)->middleware('block-test-accounts');
 */
class BlockTestAccounts
{
    public function __construct(private readonly OtpRouter $router) {}

    public function handle(Request $request, Closure $next): Response
    {
        $attribute = config('test-otp.user_mobile_attribute', 'mobile');
        $mobile = $request->user()?->{$attribute};

        if (is_string($mobile) && $mobile !== '') {
            try {
                $isTest = $this->router->isTestMobile($mobile);
            } catch (HubUnavailableException) {
                // Fail closed for test-format numbers when the hub is down.
                $isTest = true;
            }

            if ($isTest) {
                $payload = [
                    'error' => 'test_account_restricted',
                    'message' => 'Test accounts cannot perform real transactions, payments or external submissions.',
                ];

                return $request->expectsJson()
                    ? response()->json($payload, 403)
                    : abort(403, $payload['message']);
            }
        }

        return $next($request);
    }
}
