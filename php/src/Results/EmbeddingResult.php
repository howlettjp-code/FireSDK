<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Results;

/** POST /v1/embed. */
final class EmbeddingResult
{
    /**
     * @param list<list<float>> $vectors one vector per input, in input order
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly array $vectors,
        public readonly ?int $dimensions,
        public readonly string $model,
        public readonly string $provider,
        public readonly Usage $usage,
        public readonly ?float $priceUsd,
        public readonly ?string $callId,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromRaw(array $raw): self
    {
        $meta  = $raw['meta'] ?? [];
        $usage = $raw['usage'] ?? [];
        $rows  = $raw['data'] ?? [];
        usort($rows, fn (array $a, array $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return new self(
            vectors:    array_map(fn (array $r) => $r['embedding'] ?? [], $rows),
            dimensions: $raw['dimensions'] ?? null,
            model:      $raw['model'] ?? '',
            provider:   $raw['provider'] ?? '',
            usage:      new Usage($usage['input_tokens'] ?? null, $usage['output_tokens'] ?? null),
            priceUsd:   ($meta['price'] ?? [])['usd'] ?? null,
            callId:     $meta['call_id'] ?? $raw['call_id'] ?? null,
            raw:        $raw,
        );
    }
}
