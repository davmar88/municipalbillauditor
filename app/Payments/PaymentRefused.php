<?php

namespace App\Payments;

/**
 * PayPal turned a payout down outright, so no money moved and
 * it is safe to fix the cause and try again. The message says why, for the
 * owner.
 */
final class PaymentRefused extends PaymentException {}
