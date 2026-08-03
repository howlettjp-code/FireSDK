<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Results;

use ModusPromethean\FireSdk\FireClient;

/**
 * v2 — a stateful, pollable flow run.
 *
 * Unlike every other result type in this namespace, FlowRun is not
 * immutable — it holds a reference back to the client so `wait()` and
 * `resume()` read naturally as verbs on the run itself, e.g.:
 *
 *     $run = $client->runFlow(flowSlug: 'triad', input: [...]);
 *     $run->wait();
 *     if ($run->status === 'awaiting_human') {
 *         $run->resume($run->gatingStep()->stepKey, ['note' => '...']);
 *         $run->wait();
 *     }
 *     echo $run->output['content'];
 */
final class FlowRun
{
    private const TERMINAL_STATUSES = ['completed', 'failed', 'cancelled', 'awaiting_human'];

    public int $runId;
    public string $status;
    public ?int $conversationId;
    public mixed $input;
    public mixed $output;
    public ?string $error;
    public ?float $totalCostUsd;
    public ?string $startedAt;
    public ?string $completedAt;
    /** @var list<FlowStep> */
    public array $steps;
    /** @var array<string, mixed> */
    public array $raw;

    /** @param array<string, mixed> $raw */
    public function __construct(
        private readonly FireClient $client,
        array $raw,
    ) {
        $this->apply($raw);
    }

    /** @param array<string, mixed> $raw */
    private function apply(array $raw): void
    {
        $this->raw             = $raw;
        $this->runId           = $raw['run_id'];
        $this->status          = $raw['status'];
        $this->conversationId  = $raw['conversation_id'] ?? null;
        $this->input           = $raw['input'] ?? null;
        $this->output          = $raw['output'] ?? null;
        $this->error           = $raw['error'] ?? null;
        $this->totalCostUsd    = $raw['total_cost_usd'] ?? null;
        $this->startedAt       = $raw['started_at'] ?? null;
        $this->completedAt     = $raw['completed_at'] ?? null;
        $this->steps           = array_map(fn (array $s) => FlowStep::fromRaw($s), $raw['steps'] ?? []);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /** The human_gate step currently pausing this run, if status === 'awaiting_human'. */
    public function gatingStep(): ?FlowStep
    {
        foreach ($this->steps as $step) {
            if ($step->status === 'awaiting_human') {
                return $step;
            }
        }
        return null;
    }

    public function refresh(string $verbosity = 'full'): self
    {
        $this->apply($this->client->getFlowRun($this->runId, $verbosity)->raw);
        return $this;
    }

    public function wait(float $pollInterval = 1.5, float $timeout = 120.0, string $verbosity = 'full'): self
    {
        $result = $this->client->waitForFlow($this->runId, $pollInterval, $timeout, $verbosity);
        $this->apply($result->raw);
        return $this;
    }

    /** @param array<string, mixed> $humanInput */
    public function resume(string $stepKey, array $humanInput, string $verbosity = 'full'): self
    {
        $result = $this->client->resumeFlowRun($this->runId, $stepKey, $humanInput, $verbosity);
        $this->apply($result->raw);
        return $this;
    }
}
