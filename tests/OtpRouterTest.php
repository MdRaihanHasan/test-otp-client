<?php

namespace Bixcel\TestOtp\Tests;

use Bixcel\TestOtp\Exceptions\HubUnavailableException;
use Bixcel\TestOtp\Exceptions\SmsDeliveryBlockedException;
use Bixcel\TestOtp\Exceptions\TestAccountRestrictedException;
use Bixcel\TestOtp\Exceptions\TestMobileNotAllowedException;
use Bixcel\TestOtp\Exceptions\TestOtpException;
use Bixcel\TestOtp\Facades\TestOtp;
use Bixcel\TestOtp\OtpRouter;
use Bixcel\TestOtp\TestOtpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

class OtpRouterTest extends TestCase
{
    private function fakeHub(array $check = ['is_test' => true, 'mobile' => '9012345678', 'allowed' => true]): void
    {
        Http::fake([
            'hub.test/api/v1/test-mobile/check' => Http::response($check),
            'hub.test/api/v1/test-otp/generate' => Http::response(['generated' => true, 'message_id' => '01HX', 'delivery' => 'virtual_inbox'], 201),
            'hub.test/api/v1/test-otp/verify' => Http::response(['valid' => true]),
            'hub.test/api/v1/test-otp/retrieve' => Http::response(['otp' => '123456', 'mobile' => '9012345678']),
        ]);
    }

    private function useMode(string $mode): void
    {
        config(['test-otp.mode' => $mode]);
        $this->app->forgetInstance(OtpRouter::class);
        TestOtp::clearResolvedInstances();
    }

    public function test_normal_numbers_use_existing_sms_flow_without_calling_the_hub(): void
    {
        Http::fake();
        $sent = [];

        $dispatch = TestOtp::send('+61 412 345 678', function ($mobile) use (&$sent) {
            $sent[] = $mobile;

            return 'twilio-sid';
        }, ['otp' => '123456']);

        $this->assertSame('sms', $dispatch->channel);
        $this->assertSame('twilio-sid', $dispatch->providerResult);
        $this->assertSame(['+61 412 345 678'], $sent);
        Http::assertNothingSent();
    }

    public function test_unregistered_90_number_continues_normal_flow(): void
    {
        $this->fakeHub(['is_test' => false]);
        $smsCalled = false;

        $dispatch = TestOtp::send('9099999999', function () use (&$smsCalled) {
            $smsCalled = true;
        }, ['otp' => '123456']);

        $this->assertTrue($smsCalled);
        $this->assertSame('sms', $dispatch->channel);
    }

    public function test_test_mobile_goes_to_virtual_inbox_and_never_to_sms(): void
    {
        $this->fakeHub();

        $dispatch = TestOtp::send('9012345678', fn () => $this->fail('SMS provider must not be called for test IDs'), [
            'otp' => '654321', 'purpose' => 'login', 'account' => 'ACME',
        ]);

        $this->assertTrue($dispatch->isVirtual());
        $this->assertSame('01HX', $dispatch->messageId);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/test-otp/generate')
            && $r['otp'] === '654321'
            && $r['platform'] === 'brokercore'
            && $r->hasHeader('Authorization', 'Bearer stg_brokercore_abc.secret'));
    }

    public function test_platform_mode_requires_the_platform_code(): void
    {
        $this->fakeHub();

        $this->expectException(\InvalidArgumentException::class);
        TestOtp::send('9012345678', fn () => null);
    }

    public function test_disallowed_test_mobile_throws_and_sends_nothing(): void
    {
        $this->fakeHub(['is_test' => true, 'mobile' => '9012345678', 'allowed' => false, 'reason' => 'test_mobile_disabled']);

        try {
            TestOtp::send('9012345678', fn () => $this->fail('SMS must not be sent'), ['otp' => '1234']);
            $this->fail('Expected exception');
        } catch (TestMobileNotAllowedException $e) {
            $this->assertSame('test_mobile_disabled', $e->reason);
        }
    }

    public function test_hub_outage_fails_closed_for_test_format_numbers(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->expectException(HubUnavailableException::class);
        TestOtp::send('9012345678', fn () => $this->fail('SMS must not be sent when the hub is down'), ['otp' => '1234']);
    }

    public function test_hub_outage_can_fail_open_when_configured(): void
    {
        config(['test-otp.fail_closed' => false]);
        $this->app->forgetInstance(TestOtpClient::class);
        $this->useMode('platform');
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->assertSame('sms', TestOtp::send('9012345678', fn () => 'ok', ['otp' => '1234'])->channel);
    }

    public function test_hub_outage_does_not_affect_normal_numbers(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->assertSame('sms', TestOtp::send('0412345678', fn () => 'ok', ['otp' => '1234'])->channel);
    }

    public function test_server_errors_are_treated_as_outage(): void
    {
        Http::fake(['*' => Http::response(['error' => 'boom'], 503)]);

        $this->expectException(HubUnavailableException::class);
        TestOtp::isTestMobile('9012345678');
    }

    public function test_client_errors_surface_as_exceptions(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unauthenticated'], 401)]);

        $this->expectException(TestOtpException::class);
        $this->expectExceptionMessage('unauthenticated');
        TestOtp::isTestMobile('9012345678');
    }

    public function test_check_results_are_cached(): void
    {
        $this->fakeHub();

        TestOtp::isTestMobile('9012345678');
        TestOtp::isTestMobile('90 1234 5678');

        Http::assertSentCount(1);
    }

    public function test_disabled_client_treats_every_number_as_normal(): void
    {
        config(['test-otp.enabled' => false]);
        $this->app->forgetInstance(TestOtpClient::class);
        $this->useMode('platform');
        Http::fake();

        $this->assertFalse(TestOtp::isTestMobile('9012345678'));
        Http::assertNothingSent();
    }

    public function test_platform_mode_verifies_locally(): void
    {
        $this->fakeHub();

        $this->assertTrue(TestOtp::verify('9012345678', '111111', fn ($m, $otp) => $otp === '111111'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/verify'));
    }

    public function test_hub_mode_generates_and_verifies_through_the_hub(): void
    {
        $this->useMode('hub');
        $this->fakeHub();

        $dispatch = TestOtp::send('9012345678', fn () => $this->fail('no sms'), ['otp' => 'ignored']);
        $this->assertTrue($dispatch->hubManaged);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/generate') && ! isset($r['otp']));

        $this->assertTrue(TestOtp::verify('9012345678', '999999', fn () => $this->fail('local verifier must not run')));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/verify') && $r['otp'] === '999999');
    }

    public function test_hub_mode_still_verifies_real_numbers_locally(): void
    {
        $this->useMode('hub');
        Http::fake();

        $this->assertTrue(TestOtp::verify('0412345678', '1', fn () => true));
        Http::assertNothingSent();
    }

    public function test_retrieve_for_automation(): void
    {
        $this->fakeHub();

        $this->assertSame('123456', app(TestOtpClient::class)->retrieve('9012345678')['otp']);
    }

    public function test_sms_guard_blocks_test_ids(): void
    {
        $this->fakeHub();

        TestOtp::assertSmsAllowed('0412345678');

        $this->expectException(SmsDeliveryBlockedException::class);
        TestOtp::assertSmsAllowed('9012345678');
    }

    public function test_transaction_guard_blocks_test_accounts(): void
    {
        $this->fakeHub();

        TestOtp::assertNotTestAccount(null);
        TestOtp::assertNotTestAccount('0412345678');

        $this->expectException(TestAccountRestrictedException::class);
        TestOtp::assertNotTestAccount('9012345678', 'payments');
    }

    public function test_block_test_accounts_middleware(): void
    {
        $this->fakeHub();
        Route::post('/_payments', fn () => 'paid')->middleware('block-test-accounts');

        $this->actingAs(new User(['id' => 1, 'mobile' => '9012345678']))
            ->postJson('/_payments')->assertForbidden()->assertJson(['error' => 'test_account_restricted']);
        $this->actingAs(new User(['id' => 2, 'mobile' => '0412345678']))
            ->postJson('/_payments')->assertOk();
    }

    public function test_middleware_fails_closed_when_hub_is_down(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        Route::post('/_payments', fn () => 'paid')->middleware('block-test-accounts');

        $this->actingAs(new User(['id' => 1, 'mobile' => '9012345678']))->postJson('/_payments')->assertForbidden();
    }
}
