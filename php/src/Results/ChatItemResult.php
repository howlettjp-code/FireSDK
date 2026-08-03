<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Results;

/** One item inside a ParallelChatResult — can fail independently of its siblings. */
final class ChatItemResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $content,
        public readonly ?string $model,
        public readonly ?string $provider,
        public readonly ?Usage $usage,
        public readonly ?float $priceUsd,
        public readonly ?string $error,
        /** @var array<string, mixed> */
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromRaw(array $raw): self
    {
        $usageRaw = $raw['usage'] ?? null;
        $meta     = $raw['meta'] ?? [];

        return new self(
            ok:       $raw['ok'] ?? true,
            content:  $raw['content'] ?? null,
            model:    $raw['model'] ?? null,
            provider: $raw['provider'] ?? null,
            usage:    $usageRaw ? new Usage($usageRaw['input'] ?? null, $usageRaw['output'] ?? null) : null,
            priceUsd: ($meta['price'] ?? [])['usd'] ?? null,
            error:    $raw['error'] ?? null,
            raw:      $raw,
        );
    }
}
