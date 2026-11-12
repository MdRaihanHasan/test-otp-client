<?php

return [
    // Master switch. When false, every number is treated as a normal number (no hub calls).
    'enabled' => env('TEST_OTP_HUB_ENABLED', true),

    // e.g. https://otp-hub.example.com  (HTTPS required in production)
    'base_url' => env('TEST_OTP_HUB_URL'),

    // "<key_id>.<secret>" issued by the hub for THIS platform and THIS environment.
    // Staging and production MUST use different tokens.
    'token' => env('TEST_OTP_HUB_TOKEN'),

    // Platform slug registered in the hub (oxcel, brokercore, dealercore, bixcel, ...).
    'platform' => env('TEST_OTP_HUB_PLATFORM'),

    /*
     | OTP mode
     | - "platform": your app keeps generating + validating OTPs exactly as today.
     |               For test IDs the code is deposited in the virtual inbox instead of SMS.
     | - "hub":      the hub generates the code and your app validates through the hub.
     */
    'mode' => env('TEST_OTP_HUB_MODE', 'platform'),

    // Only numbers matching this pattern are looked up in the hub. All other
    // numbers skip the hub entirely, so the normal SMS flow is untouched.
    'candidate_pattern' => '/^90\d{8}$/',

    'timeout' => (float) env('TEST_OTP_HUB_TIMEOUT', 3),
    'retries' => (int) env('TEST_OTP_HUB_RETRIES', 2),

    // Seconds to cache "is this a test ID?" answers (0 = no cache).
    'cache_ttl' => (int) env('TEST_OTP_HUB_CACHE_TTL', 60),

    /*
     | If the hub cannot be reached for a 90XXXXXXXX number, refuse to send
     | (fail closed) so a test OTP can never leak to an SMS provider.
     */
    'fail_closed' => env('TEST_OTP_HUB_FAIL_CLOSED', true),

    // Attribute on your User model holding the mobile number (used by middleware).
    'user_mobile_attribute' => env('TEST_OTP_HUB_USER_MOBILE_ATTRIBUTE', 'mobile'),
];
