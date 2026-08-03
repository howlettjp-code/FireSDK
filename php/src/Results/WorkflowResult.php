<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Results;

/**
 * POST /v1/workflows/{slug}. Deliberately thin — each workflow (image,
 * article, ...) defines its own response contract server-side
 * (WorkflowContract), so this does not attempt to model every field of
 * every workflow. Use `raw` for anything workflow-specific. A separate
 * root from ChatResult/ImageResult on purpose: L2 is a different layer
 * with a different contract per slug, not a variant of a one-shot call.
 */
final class WorkflowResult
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $workflow,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromRaw(string $workflow, array $raw): self
    {
        return new self($workflow, $raw);
    }
}
