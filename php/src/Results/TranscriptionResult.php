<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Results;

/** POST /v1/transcribe. language/duration are null when the provider omits them. */
final class TranscriptionResult
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $text,
        public readonly ?string $language,
        public readonly ?float $duration,
        public readonly string $model,
        public readonly string $provider,
        public readonly ?float $priceUsd,
        public readonly ?string $callId,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromRaw(array $raw): self
    {
        $meta = $raw['meta'] ?? [];

        return new self(
            text:     $raw['text'] ?? '',
            language: $raw['language'] ?? null,
            duration: isset($raw['duration']) ? (float) $raw['duration'] : null,
            model:    $raw['model'] ?? '',
            provider: $raw['provider'] ?? '',
            priceUsd: ($meta['price'] ?? [])['usd'] ?? null,
            callId:   $meta['call_id'] ?? $raw['call_id'] ?? null,
            raw:      $raw,
        );
    }
}
