<?php

declare(strict_types=1);

/**
 * Runs Fire's bundled "triad" flow: two hot agents (one agreeable, one
 * contrarian) answer your question in parallel, you review both, then a
 * cool synthesizer writes the final answer. Mirrors
 * python/examples/triad_example.py exactly.
 *
 * Usage:
 *     FIRE_TOKEN=fire_sk_... php examples/triad_example.php "Should cities ban cars downtown?"
 *
 * Get a token from whoever gave you access to this SDK, or self-serve one
 * with a tier code (see README.md's "Getting a token" section).
 */

require __DIR__ . '/../vendor/autoload.php';

use ModusPromethean\FireSdk\FireClient;

function main(): void
{
    $token = getenv('FIRE_TOKEN');
    if ($token === false || $token === '') {
        fwrite(STDERR, "Set FIRE_TOKEN in your environment first.\n");
        exit(1);
    }

    $question = implode(' ', array_slice($_SERVER['argv'], 1)) ?: 'Should remote work be the default for knowledge workers?';

    $client = new FireClient(token: $token);

    echo "Question: {$question}\n\n";
    echo "Starting the triad flow (hot_1 + hot_2 run in parallel)...\n";
    $run = $client->runFlow(flowSlug: 'triad', input: ['prompt_package' => $question]);
    echo "  run_id={$run['run_id']} status={$run['status']}\n";

    // Execution is always queued — poll until it lands somewhere interesting.
    $run = $client->waitForFlow($run['run_id']);
    echo "  -> {$run['status']}\n\n";

    if ($run['status'] === 'awaiting_human') {
        $gate = null;
        foreach ($run['steps'] as $step) {
            if ($step['status'] === 'awaiting_human') {
                $gate = $step;
                break;
            }
        }

        echo "Paused for human review: {$gate['prompt_for_human']}\n\n";
        echo "Agreeable take:\n  {$gate['context']['hot_1']}\n\n";
        echo "Contrarian take:\n  {$gate['context']['hot_2']}\n\n";

        echo 'Guidance for the synthesizer (or press Enter to let it decide): ';
        $note = trim((string) fgets(STDIN));

        $run = $client->resumeFlowRun($run['run_id'], $gate['step_key'], ['note' => $note]);
        $run = $client->waitForFlow($run['run_id']);
        echo "\n  -> {$run['status']}\n";
    }

    if ($run['status'] === 'completed') {
        echo "\n=== Final synthesized answer ===\n";
        echo $run['output']['content'] . "\n";
        echo "\n(cost: \$" . number_format((float) $run['total_cost_usd'], 6) . ")\n";
    } else {
        echo "\nRun ended in status='{$run['status']}': " . ($run['error'] ?? '') . "\n";
    }
}

main();
