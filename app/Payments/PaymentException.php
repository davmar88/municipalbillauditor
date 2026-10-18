<?php

namespace App\Payments;

use RuntimeException;

/**
 * Paystack or PayPal couldn't do what we asked. The message is safe to show
 * the player; the details go to the log.
 */
class PaymentException extends RuntimeException {}
