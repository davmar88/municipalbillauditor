<?php

namespace App\Services\Reading;

use RuntimeException;

/**
 * The bill couldn't be read automatically. The message is safe to show the
 * customer; the bill waits for someone to type in the details.
 */
class BillReadingFailed extends RuntimeException {}
