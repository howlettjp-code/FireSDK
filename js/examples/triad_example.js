#!/usr/bin/env node
/**
 * Runs Fire's bundled "triad" flow: two hot agents (one agreeable, one
 * contrarian) answer your question in parallel, you review both, then a
 * cool synthesizer writes the final answer. Mirrors
 * python/examples/triad_example.py and php/examples/triad_example.php
 * exactly.
 *
 * Usage:
 *     FIRE_TOKEN=fire_sk_... node examples/triad_example.js "Should cities ban cars downtown?"
 *
 * Get a token from whoever gave you access to this SDK, or self-serve one
 * with a tier code (see README.md's "Getting a token" section).
 */

import { createInterface } from 'node:readline/promises';
import { stdin, stdout } from 'node:process';

import { FireClient } from '../src/FireClient.js';

async function main() {
  const token = process.env.FIRE_TOKEN;
  if (!token) {
    console.error('Set FIRE_TOKEN in your environment first.');
    process.exit(1);
  }

  const question = process.argv.slice(2).join(' ') || 'Should remote work be the default for knowledge workers?';

  const client = new FireClient({ token });

  console.log(`Question: ${question}\n`);
  console.log('Starting the triad flow (hot_1 + hot_2 run in parallel)...');
  let run = await client.runFlow({ flowSlug: 'triad', input: { prompt_package: question } });
  console.log(`  run_id=${run.run_id} status=${run.status}`);

  // Execution is always queued — poll until it lands somewhere interesting.
  run = await client.waitForFlow(run.run_id);
  console.log(`  -> ${run.status}\n`);

  if (run.status === 'awaiting_human') {
    const gate = run.steps.find((s) => s.status === 'awaiting_human');

    console.log(`Paused for human review: ${gate.prompt_for_human}\n`);
    console.log(`Agreeable take:\n  ${gate.context.hot_1}\n`);
    console.log(`Contrarian take:\n  ${gate.context.hot_2}\n`);

    const rl = createInterface({ input: stdin, output: stdout });
    const note = await rl.question('Guidance for the synthesizer (or press Enter to let it decide): ');
    rl.close();

    run = await client.resumeFlowRun(run.run_id, gate.step_key, { note });
    run = await client.waitForFlow(run.run_id);
    console.log(`\n  -> ${run.status}`);
  }

  if (run.status === 'completed') {
    console.log('\n=== Final synthesized answer ===');
    console.log(run.output.content);
    console.log(`\n(cost: $${run.total_cost_usd.toFixed(6)})`);
  } else {
    console.log(`\nRun ended in status='${run.status}': ${run.error ?? ''}`);
  }
}

main();
