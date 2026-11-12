<?php

namespace Bixcel\TestOtp\Exceptions;

/** Thrown by the SMS guard when code attempts to send an SMS to a test mobile ID. */
class SmsDeliveryBlockedException extends TestOtpException {}
