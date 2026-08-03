<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Results;

final class FlowStep
{
    /**
     * @param list<string> $dependsOn
     * @param array<string, mixed>|null $output
     * @param array<string, mixed>|null $context
     * @param array<string, mixed>|null $humanInput
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $stepKey,
        public readonly string $kind,
        public readonly string $status,
        public readonly array $dependsOn,
        public readonly ?string $error,
        public readonly ?array $output,
        public readonly ?array $context,
        public readonly ?string $promptForHuman,
        public readonly ?array $humanInput,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromRaw(array $raw): self
    {
        return new self(
            stepKey:        $raw['step_key'],
            kind:            $raw['kind'],
            status:          $raw['status'],
            dependsOn:       $raw['depends_on'] ?? [],
            error:           $raw['error'] ?? null,
            output:          $raw['output'] ?? null,
            context:         $raw['context'] ?? null,
            promptForHuman:  $raw['prompt_for_human'] ?? null,
            humanInput:      $raw['human_input'] ?? null,
            raw:             $raw,
        );
    }
}
