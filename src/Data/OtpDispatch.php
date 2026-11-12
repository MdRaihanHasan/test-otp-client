<?php

namespace Bixcel\TestOtp\Data;

final class OtpDispatch
{
    public function __construct(
        /** "virtual_inbox" for test IDs, "sms" for real numbers. */
        public readonly string $channel,
        public readonly ?string $messageId = null,
        /** True when the hub generated the code, so verification must go through the hub. */
        public readonly bool $hubManaged = false,
        /** Whatever your real SMS sender returned (normal numbers only). */
        public readonly mixed $providerResult = null,
    ) {}

    public function isVirtual(): bool
    {
        return $this->channel === 'virtual_inbox';
    }
}
