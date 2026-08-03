<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Exceptions;

/**
 * 409 — e.g. resuming a flow run that isn't awaiting human input, or with
 * a step_key that doesn't match the step currently gating it.
 */
class FireConflictError extends FireError
{
}
