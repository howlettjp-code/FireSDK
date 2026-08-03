<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Exceptions;

/**
 * 422 — request failed validation. `response` includes field errors
 * (Laravel-style `errors`) or, for flow specs, a `details` list from
 * FlowValidator.
 */
class FireValidationError extends FireError
{
}
