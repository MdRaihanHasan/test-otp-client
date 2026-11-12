<?php

namespace Bixcel\TestOtp\Tests;

use Bixcel\TestOtp\TestOtpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [TestOtpServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['TestOtp' => \Bixcel\TestOtp\Facades\TestOtp::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('test-otp', [
            'enabled' => true,
            'base_url' => 'https://hub.test',
            'token' => 'stg_brokercore_abc.secret',
            'platform' => 'brokercore',
            'mode' => 'platform',
            'candidate_pattern' => '/^90\d{8}$/',
            'timeout' => 1,
            'retries' => 0,
            'cache_ttl' => 60,
            'fail_closed' => true,
            'user_mobile_attribute' => 'mobile',
        ]);
    }
}
