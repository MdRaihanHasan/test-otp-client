<?php

namespace Bixcel\TestOtp\Exceptions;

/** Test accounts may not perform real transactions, payments or external submissions. */
class TestAccountRestrictedException extends TestOtpException
{
    public function __construct(string $action = 'this action')
    {
        parent::__construct("Test accounts are not permitted to perform {$action}.");
    }
}
