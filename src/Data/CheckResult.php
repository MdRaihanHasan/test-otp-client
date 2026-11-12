<?php

namespace Bixcel\TestOtp\Data;

final class CheckResult
{
    public function __construct(
        public readonly bool $isTest,
        public readonly ?string $mobile = null,
        public readonly bool $allowed = false,
        public readonly ?string $reason = null,
        public readonly array $restrictions = [],
    ) {}

    public static function normal(): self
    {
        return new self(false);
    }

    public static function fromResponse(array $data): self
    {
        $isTest = (bool) ($data['is_test'] ?? false);

        return new self(
            $isTest,
            $data['mobile'] ?? null,
            $isTest && (bool) ($data['allowed'] ?? true),
            $data['reason'] ?? null,
            $data['restrictions'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'is_test' => $this->isTest,
            'mobile' => $this->mobile,
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'restrictions' => $this->restrictions,
        ];
    }
}
