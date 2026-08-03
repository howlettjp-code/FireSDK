<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Results;

final class ImageFile
{
    public function __construct(
        public readonly ?string $b64,
        public readonly ?string $url,
    ) {
    }
}
