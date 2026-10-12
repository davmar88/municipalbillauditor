<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * The processor couldn't start or make a charge. The message is safe to show.
 */
class PaymentFailed extends RuntimeException {}
