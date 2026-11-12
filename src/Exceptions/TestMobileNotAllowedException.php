<?php

namespace Bixcel\TestOtp\Exceptions;

/** The number is a registered test ID but is disabled / not enabled for this platform or environment. */
class TestMobileNotAllowedException extends TestOtpException
{
    public function __construct(public readonly ?string $reason = null)
    {
        parent::__construct('This test mobile ID is not allowed here'.($reason ? " ({$reason})" : '').'.');
    }
}
