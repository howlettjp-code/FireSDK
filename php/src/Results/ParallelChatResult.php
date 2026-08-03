<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Results;

/** POST /v1/chat/parallel — N independent completions, input order preserved. */
final class ParallelChatResult
{
    /**
     * @param list<ChatItemResult> $results
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly array $results,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromRaw(array $raw): self
    {
        $results = array_map(
            fn (array $r) => ChatItemResult::fromRaw($r),
            $raw['results'] ?? [],
        );

        return new self($results, $raw);
    }
}
