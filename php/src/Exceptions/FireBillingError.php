<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Exceptions;

/** 402/500 — no billing tier resolvable, or account has no balance left. */
class FireBillingError extends FireError
{
}
