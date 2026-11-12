<?php

namespace Bixcel\TestOtp\Exceptions;

/** Thrown when the hub cannot be reached for a test-format number (fail closed: no SMS is sent). */
class HubUnavailableException extends TestOtpException {}
