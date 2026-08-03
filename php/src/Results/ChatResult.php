<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Results;

/** POST /v1/chat — one chat completion. */
final class ChatResult
{
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly string $provider,
        public readonly Usage $usage,
        public readonly ?float $priceUsd,
        public readonly ?int $logId,
        public readonly mixed $toolCalls,
        /** @var array<string, mixed> Always the full original response — an escape hatch for anything not modeled above. */
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromRaw(array $raw): self
    {
        $usage = $raw['usage'] ?? [];
        $meta  = $raw['meta'] ?? [];

        return new self(
            content:   $raw['content'] ?? '',
            model:     $raw['model'] ?? '',
            provider:  $raw['provider'] ?? '',
            usage:     new Usage($usage['input'] ?? null, $usage['output'] ?? null),
            priceUsd:  ($meta['price'] ?? [])['usd'] ?? null,
            logId:     $meta['log_id'] ?? $raw['log_id'] ?? null,
            toolCalls: $raw['tool_calls'] ?? null,
            raw:       $raw,
        );
    }
}
