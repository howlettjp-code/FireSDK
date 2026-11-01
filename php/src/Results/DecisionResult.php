<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Results;

/** POST /v1/decide — typed answers (noul / choice / score) keyed by question name. */
final class DecisionResult
{
    /**
     * @param array<string, array<string, mixed>> $answers
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly array $answers,
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

        return new self(
            answers:  $raw['answers'] ?? [],
            model:    $raw['model'] ?? '',
            provider: $raw['provider'] ?? '',
            usage:    new Usage($usage['input_tokens'] ?? null, $usage['output_tokens'] ?? null),
            priceUsd: ($meta['price'] ?? [])['usd'] ?? null,
            callId:   $meta['call_id'] ?? $raw['call_id'] ?? null,
            raw:      $raw,
        );
    }
}
