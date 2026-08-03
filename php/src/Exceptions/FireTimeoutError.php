<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Exceptions;

use RuntimeException;

/**
 * Raised by FireClient::waitForFlow() when a run is still pending/running
 * after the given timeout — a client-side polling timeout, not a Fire API
 * error, so it deliberately does not extend FireError.
 */
class FireTimeoutError extends RuntimeException
{
}
