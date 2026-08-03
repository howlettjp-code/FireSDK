<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Results;

final class Usage
{
    public function __construct(
        public readonly ?int $input,
        public readonly ?int $output,
    ) {
    }
}
