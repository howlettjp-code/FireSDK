import { test, describe } from 'node:test';
import assert from 'node:assert/strict';

import { FireClient } from '../src/FireClient.js';
import {
  FireAuthError,
  FireConflictError,
  FireNotFoundError,
  FireTimeoutError,
  FireValidationError,
} from '../src/errors.js';
import { FlowRun } from '../src/results.js';
import { Species } from '../src/species.js';

/**
 * Unit tests for FireClient — a fake fetch stands in for the network.
 * Mirrors the Python/PHP SDKs' mocked-request test suites (same
 * routing/error-mapping/verbosity assertions), see python/tests/ and
 * php/tests/.
 */

function fakeFetch(status, jsonBody) {
  const calls = [];
  const fetchImpl = async (url, init) => {
    calls.push({ url, init });
    return {
      ok: status >= 200 && status < 300,
      status,
      text: async () => JSON.stringify(jsonBody),
    };
  };
  return { fetchImpl, calls };
}

function clientWith(status, jsonBody, { token = 'fire_sk_test' } = {}) {
  const { fetchImpl, calls } = fakeFetch(status, jsonBody);
  const client = new FireClient({ token, baseUrl: 'https://fire.example.test', fetchImpl });
  return { client, calls };
}

describe('routing', () => {
  test('capabilities needs no token and hits v1', async () => {
    const { client, calls } = clientWith(200, { planes: {} }, { token: null });
    await client.capabilities();
    assert.equal(calls[0].url, 'https://fire.example.test/v1/capabilities');
    assert.equal(calls[0].init.headers.Authorization, undefined);
  });

  test('chat hits v1', async () => {
    const { client, calls } = clientWith(200, { content: 'hi' });
    await client.chat([{ role: 'user', content: 'hi' }]);
    assert.equal(calls[0].url, 'https://fire.example.test/v1/chat');
    assert.equal(calls[0].init.headers.Authorization, 'Bearer fire_sk_test');
  });

  test('chatParallel hits v1', async () => {
    const { client, calls } = clientWith(200, { results: [] });
    await client.chatParallel([{ messages: [{ role: 'user', content: 'x' }] }]);
    assert.equal(calls[0].url, 'https://fire.example.test/v1/chat/parallel');
  });

  test('agent config CRUD hits v2', async () => {
    const { client, calls } = clientWith(200, { slug: 'hot' });
    await client.getAgentConfig('hot');
    assert.equal(calls[0].url, 'https://fire.example.test/v2/agent-configs/hot');
  });

  test('runFlow with slug hits the slug endpoint', async () => {
    const { client, calls } = clientWith(202, { run_id: 1, status: 'pending' });
    await client.runFlow({ flowSlug: 'triad', input: { prompt_package: 'x' } });
    assert.equal(calls[0].url, 'https://fire.example.test/v2/flows/triad/run');
  });

  test('runFlow with inline spec hits the deprecated body endpoint', async () => {
    const { client, calls } = clientWith(202, { run_id: 1, status: 'pending' });
    await client.runFlow({ flow: { steps: [] }, input: {} });
    assert.equal(calls[0].url, 'https://fire.example.test/v2/flows/run');
  });

  test('getFlowRun hits v2', async () => {
    const { client, calls } = clientWith(200, { run_id: 1, status: 'completed' });
    await client.getFlowRun(1);
    assert.equal(calls[0].url, 'https://fire.example.test/v2/flows/runs/1');
  });

  test('resumeFlowRun hits v2', async () => {
    const { client, calls } = clientWith(202, { run_id: 1, status: 'running' });
    await client.resumeFlowRun(1, 'review', { note: 'x' });
    assert.equal(calls[0].url, 'https://fire.example.test/v2/flows/runs/1/resume');
  });

  test('image hits v1', async () => {
    const { client, calls } = clientWith(200, { images: [], model: 'm', provider: 'p' });
    await client.image('a cat');
    assert.equal(calls[0].url, 'https://fire.example.test/v1/image');
  });

  test('runWorkflow hits v1/workflows/{slug}', async () => {
    const { client, calls } = clientWith(200, { ok: true });
    await client.runWorkflow('article', { source_text: '...' });
    assert.equal(calls[0].url, 'https://fire.example.test/v1/workflows/article');
    assert.equal(JSON.parse(calls[0].init.body).source_text, '...');
  });
});

describe('typed results', () => {
  test('chat returns a ChatResult', async () => {
    const body = {
      content: 'hi there', model: 'Claude Sonnet 4.5', provider: 'edenai',
      usage: { input: 10, output: 5 }, meta: { price: { usd: 0.001 }, log_id: 42 },
    };
    const { client } = clientWith(200, body);
    const result = await client.chat([{ role: 'user', content: 'hi' }]);
    assert.equal(result.content, 'hi there');
    assert.equal(result.usage.input, 10);
    assert.equal(result.priceUsd, 0.001);
    assert.equal(result.logId, 42);
    assert.deepEqual(result.raw, body);
  });

  test('chatParallel returns per-item results', async () => {
    const body = { results: [
      { ok: true, content: 'a', model: 'm1', usage: { input: 1, output: 1 }, meta: { price: { usd: 0.0001 } } },
      { ok: false, error: 'boom' },
    ] };
    const { client } = clientWith(200, body);
    const result = await client.chatParallel([{ messages: [] }, { messages: [] }]);
    assert.equal(result.results.length, 2);
    assert.equal(result.results[0].ok, true);
    assert.equal(result.results[0].content, 'a');
    assert.equal(result.results[1].ok, false);
    assert.equal(result.results[1].error, 'boom');
  });

  test('image returns an ImageResult', async () => {
    const body = { images: [{ b64: 'abc', url: null }], model: 'gpt-image-1', provider: 'openai', meta: { price: { usd: 0.06 } } };
    const { client } = clientWith(200, body);
    const result = await client.image('a cat');
    assert.equal(result.images.length, 1);
    assert.equal(result.images[0].b64, 'abc');
    assert.equal(result.priceUsd, 0.06);
  });

  test('runWorkflow returns a thin WorkflowResult', async () => {
    const body = { anything: 'the workflow wants to return' };
    const { client } = clientWith(200, body);
    const result = await client.runWorkflow('article', { source_text: '...' });
    assert.equal(result.workflow, 'article');
    assert.deepEqual(result.raw, body);
  });

  test('runFlow returns a FlowRun', async () => {
    const { client } = clientWith(202, { run_id: 5, status: 'pending', steps: [] });
    const run = await client.runFlow({ flowSlug: 'triad', input: {} });
    assert.ok(run instanceof FlowRun);
    assert.equal(run.runId, 5);
    assert.equal(run.status, 'pending');
  });

  test('flow run steps are typed and the gating step is findable', async () => {
    const body = {
      run_id: 5, status: 'awaiting_human', steps: [
        { step_key: 'hot_1', kind: 'agent_call', status: 'completed', depends_on: [], error: null },
        { step_key: 'review', kind: 'human_gate', status: 'awaiting_human', depends_on: ['hot_1'],
          error: null, prompt_for_human: 'go?', context: { hot_1: '...' } },
      ],
    };
    const { client } = clientWith(200, body);
    const run = await client.getFlowRun(5);
    const gate = run.gatingStep();
    assert.ok(gate);
    assert.equal(gate.stepKey, 'review');
    assert.equal(gate.promptForHuman, 'go?');
  });
});

describe('species registry', () => {
  test('known constant resolves to a species_name string', () => {
    assert.equal(Species.anthropic.CLAUDE_SONNET_4_5, 'claude-sonnet-4-5');
  });

  test('species usable directly as speciesName', async () => {
    const { client, calls } = clientWith(200, { content: 'hi' });
    await client.chat([{ role: 'user', content: 'hi' }], { speciesName: Species.anthropic.CLAUDE_SONNET_4_5 });
    assert.equal(JSON.parse(calls[0].init.body).species_name, 'claude-sonnet-4-5');
  });
});

describe('verbosity', () => {
  test('default verbosity sends no query param', async () => {
    const { client, calls } = clientWith(200, { run_id: 1, status: 'completed' });
    await client.getFlowRun(1);
    assert.ok(!calls[0].url.includes('verbosity'));
  });

  test('compact verbosity sends a query param', async () => {
    const { client, calls } = clientWith(200, { run_id: 1, status: 'completed' });
    await client.getFlowRun(1, { verbosity: 'compact' });
    assert.ok(calls[0].url.includes('verbosity=compact'));
  });
});

describe('error mapping', () => {
  test('401 raises FireAuthError', async () => {
    const { client } = clientWith(401, { description: 'no token' });
    await assert.rejects(() => client.status(), FireAuthError);
  });

  test('404 raises FireNotFoundError', async () => {
    const { client } = clientWith(404, { description: 'nope' });
    await assert.rejects(() => client.getFlowRun(999), FireNotFoundError);
  });

  test('409 raises FireConflictError', async () => {
    const { client } = clientWith(409, { error_code: 'FLOW_RESUME_CONFLICT', description: 'not waiting' });
    await assert.rejects(() => client.resumeFlowRun(1, 'review', {}), FireConflictError);
  });

  test('422 raises FireValidationError carrying details', async () => {
    const { client } = clientWith(422, { error_code: 'FLOW_INVALID', description: 'bad spec', details: ['x'] });
    await assert.rejects(
      () => client.runFlow({ flow: { steps: [] }, input: {} }),
      (err) => {
        assert.ok(err instanceof FireValidationError);
        assert.deepEqual(err.response.details, ['x']);
        return true;
      },
    );
  });
});

describe('waitForFlow', () => {
  test('stops on terminal status', async () => {
    const { client } = clientWith(200, { run_id: 1, status: 'completed' });
    const run = await client.waitForFlow(1, { pollInterval: 5, timeout: 1000 });
    assert.equal(run.status, 'completed');
  });

  test('stops on awaiting_human', async () => {
    const { client } = clientWith(200, { run_id: 1, status: 'awaiting_human' });
    const run = await client.waitForFlow(1, { pollInterval: 5, timeout: 1000 });
    assert.equal(run.status, 'awaiting_human');
  });

  test('times out while still running', async () => {
    const { client } = clientWith(200, { run_id: 1, status: 'running' });
    await assert.rejects(() => client.waitForFlow(1, { pollInterval: 5, timeout: 30 }), FireTimeoutError);
  });

  test('FlowRun#wait mutates in place', async () => {
    const { client } = clientWith(200, { run_id: 1, status: 'completed', output: { content: 'done' } });
    const running = new FlowRun(client, { run_id: 1, status: 'running' });
    const result = await running.wait({ pollInterval: 5, timeout: 1000 });
    assert.equal(result, running); // mutates and returns self
    assert.equal(running.status, 'completed');
    assert.equal(running.output.content, 'done');
  });
});

describe('redeemTierCode', () => {
  test('returns token, response, and a ready client', async () => {
    const { fetchImpl, calls } = fakeFetch(200, { token: 'fire_sk_new', account_id: 1 });
    const { token, response, client } = await FireClient.redeemTierCode('CODE', 'Name', 'e@example.test', {
      baseUrl: 'https://fire.example.test',
      fetchImpl,
    });
    assert.equal(token, 'fire_sk_new');
    assert.equal(response.account_id, 1);
    assert.equal(client.token, 'fire_sk_new');
    assert.equal(calls[0].url, 'https://fire.example.test/v1/billing/tier/redeem');
  });
});
