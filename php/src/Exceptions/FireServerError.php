<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Exceptions;

/** 5xx not covered above — provider/pipeline failure, etc. */
class FireServerError extends FireError
{
}
