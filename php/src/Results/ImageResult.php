<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Results;

/** POST /v1/image. */
final class ImageResult
{
    /**
     * @param list<ImageFile> $images
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly array $images,
        public readonly string $model,
        public readonly string $provider,
        public readonly ?float $priceUsd,
        public readonly ?int $logId,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromRaw(array $raw): self
    {
        $meta   = $raw['meta'] ?? [];
        $images = array_map(
            fn (array $i) => new ImageFile($i['b64'] ?? null, $i['url'] ?? null),
            $raw['images'] ?? [],
        );

        return new self(
            images:   $images,
            model:    $raw['model'] ?? '',
            provider: $raw['provider'] ?? '',
            priceUsd: ($meta['price'] ?? [])['usd'] ?? null,
            logId:    $meta['log_id'] ?? null,
            raw:      $raw,
        );
    }
}
