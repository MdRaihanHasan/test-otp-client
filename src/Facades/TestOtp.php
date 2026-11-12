<?php

namespace Bixcel\TestOtp\Facades;

use Bixcel\TestOtp\OtpRouter;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Bixcel\TestOtp\Data\CheckResult check(string $mobile)
 * @method static bool isTestMobile(string $mobile)
 * @method static \Bixcel\TestOtp\Data\OtpDispatch send(string $mobile, \Closure $smsSender, array $context = [])
 * @method static bool verify(string $mobile, string $otp, \Closure $localVerifier, ?string $purpose = null)
 * @method static void assertSmsAllowed(string $mobile)
 * @method static void assertNotTestAccount(?string $mobile, string $action = 'this action')
 * @method static \Bixcel\TestOtp\TestOtpClient client()
 *
 * @see OtpRouter
 */
class TestOtp extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return OtpRouter::class;
    }
}
