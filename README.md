# bixcel/test-otp-client

Laravel client for the **Centralised Test Mobile ID & Virtual OTP Inbox** (Test OTP Hub).

Use registered numeric test mobile IDs (`90XXXXXXXX`) on login and registration flows. Their OTPs go to a
shared virtual inbox instead of Twilio or any other SMS provider. Real mobile numbers keep using your
existing SMS flow, unchanged.

* Numbers that don't look like a test ID never reach the hub: no extra latency for real users.
* A number counts as a test account only if the hub's registry says so. The `90` prefix alone is not enough.
* **Fail closed:** if the hub is unreachable for a `90XXXXXXXX` number, nothing is sent.
* Guards stop test accounts from sending real SMS, making payments, or submitting to external systems.

Supports PHP 8.2+ and Laravel 11, 12 and 13.

## Installation

```bash
composer require bixcel/test-otp-client
```

```bash
php artisan vendor:publish --tag=test-otp-config
```

The service provider, the `TestOtp` facade and the `block-test-accounts` middleware are auto-discovered.

## Configuration

Ask your Test OTP Hub admin for an API credential for **your platform and environment**.
Staging and production use different tokens.

```dotenv
TEST_OTP_HUB_URL=https://otp-hub.example.com
TEST_OTP_HUB_PLATFORM=brokercore
TEST_OTP_HUB_TOKEN=stg_brokercore_xxxxxxxxxxxxxxxx.your-secret
TEST_OTP_HUB_MODE=platform        # or "hub"
```

| Variable | Default | Meaning |
|---|---|---|
| `TEST_OTP_HUB_ENABLED` | `true` | Set to `false` to treat every number as a real number |
| `TEST_OTP_HUB_URL` | — | Base URL of the hub (HTTPS) |
| `TEST_OTP_HUB_TOKEN` | — | `<key_id>.<secret>` issued by the hub |
| `TEST_OTP_HUB_PLATFORM` | — | Your platform slug in the hub |
| `TEST_OTP_HUB_MODE` | `platform` | `platform`: you generate and validate codes. `hub`: the hub does |
| `TEST_OTP_HUB_TIMEOUT` | `3` | HTTP timeout in seconds |
| `TEST_OTP_HUB_RETRIES` | `2` | Retries on connection errors and 5xx |
| `TEST_OTP_HUB_CACHE_TTL` | `60` | Seconds to cache "is this a test ID?" answers |
| `TEST_OTP_HUB_FAIL_CLOSED` | `true` | Refuse to send when the hub is down for a `90…` number |
| `TEST_OTP_HUB_USER_MOBILE_ATTRIBUTE` | `mobile` | User attribute read by the middleware |

## Sending OTPs

### `platform` mode (recommended)

Your app keeps generating and validating OTPs exactly as it does today. Only delivery changes.

```php
use Bixcel\TestOtp\Facades\TestOtp;

$code = $this->otps->createFor($user->mobile, 'login');   // your existing logic
$body = "Your BrokerCore code is {$code}";

TestOtp::send(
    $user->mobile,
    fn (string $mobile) => $this->twilio->send($mobile, $body),   // only called for real numbers
    [
        'otp' => $code,               // stored in the virtual inbox for test IDs
        'message' => $body,
        'purpose' => 'login',         // login | registration | password_reset | …
        'account' => $user->company?->name,
        'application' => 'broker-portal',
    ],
);

// Validation: unchanged — use your existing verifier.
```

### `hub` mode

The hub generates the code. Test IDs are verified through the hub, and real numbers through your own verifier.

```php
TestOtp::send($mobile, fn ($m) => $this->sendRealSms($m), ['purpose' => 'login']);

$valid = TestOtp::verify(
    $mobile,
    $request->input('otp'),
    fn ($mobile, $otp) => $this->otps->verify($mobile, $otp),   // used for real numbers
    'login',
);
```

### Handling errors

```php
use Bixcel\TestOtp\Exceptions\HubUnavailableException;
use Bixcel\TestOtp\Exceptions\TestMobileNotAllowedException;

try {
    $this->sendOtp($user);
} catch (TestMobileNotAllowedException $e) {   // disabled, or not enabled for this platform/environment
    return back()->withErrors(['mobile' => 'This test account is not enabled here.']);
} catch (HubUnavailableException $e) {         // hub down: nothing was sent
    report($e);
    return back()->withErrors(['mobile' => 'Could not send a code. Please try again.']);
}
```

## Guards

**SMS provider guard.** Call this right before every SMS-provider API call, so no forgotten code path can text a test ID:

```php
TestOtp::assertSmsAllowed($to);   // throws SmsDeliveryBlockedException for test IDs
```

**Middleware.** Blocks test accounts from payments, transactions and external submissions:

```php
Route::middleware(['auth', 'block-test-accounts'])->group(function () {
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::post('/applications/{application}/submit', [SubmissionController::class, 'store']);
});
```

**In jobs and services:**

```php
TestOtp::assertNotTestAccount($applicant->mobile, 'lender submissions');   // throws TestAccountRestrictedException
```

## Other helpers

```php
TestOtp::isTestMobile('9012345678');        // bool
TestOtp::check('9012345678');               // CheckResult: isTest, allowed, reason, restrictions

// Automated staging E2E tests only (credential needs the otp.retrieve ability):
app(\Bixcel\TestOtp\TestOtpClient::class)->retrieve('9012345678', 'login')['otp'];
```

## How routing works

```
mobile entered
   │
   ├─ not 90XXXXXXXX ─────────────────────────► your SMS flow (no hub call)
   │
   └─ 90XXXXXXXX → hub /test-mobile/check
          ├─ is_test = false ─────────────────► your SMS flow
          ├─ is_test = true, allowed = false ─► TestMobileNotAllowedException
          ├─ is_test = true, allowed = true ──► hub /test-otp/generate → virtual inbox
          └─ hub unreachable ─────────────────► HubUnavailableException (nothing sent)
```

## Testing your integration

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    '*/api/v1/test-mobile/check' => Http::response(['is_test' => true, 'mobile' => '9012345678', 'allowed' => true]),
    '*/api/v1/test-otp/generate' => Http::response(['generated' => true, 'message_id' => 'x'], 201),
]);
```

Run this package's own tests:

```bash
composer install && composer test
```

## License

MIT — see [LICENSE](LICENSE).
