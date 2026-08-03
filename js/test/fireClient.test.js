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
