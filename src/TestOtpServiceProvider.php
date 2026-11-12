<?php

namespace Bixcel\TestOtp;

use Bixcel\TestOtp\Http\Middleware\BlockTestAccounts;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class TestOtpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/test-otp.php', 'test-otp');

        $this->app->singleton(TestOtpClient::class, fn ($app) => new TestOtpClient(
            $app->make(HttpFactory::class),
            $app->make(Cache::class),
            $app['config']->get('test-otp'),
        ));

        $this->app->singleton(OtpRouter::class, fn ($app) => new OtpRouter(
            $app->make(TestOtpClient::class),
            $app['config']->get('test-otp.mode', 'platform'),
        ));

        $this->app->alias(OtpRouter::class, 'test-otp');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/test-otp.php' => config_path('test-otp.php'),
        ], 'test-otp-config');

        $this->app->make(Router::class)->aliasMiddleware('block-test-accounts', BlockTestAccounts::class);
    }
}
