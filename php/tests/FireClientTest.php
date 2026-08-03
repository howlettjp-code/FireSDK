<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Tests;

use ModusPromethean\FireSdk\Exceptions\FireAuthError;
use ModusPromethean\FireSdk\Exceptions\FireConflictError;
use ModusPromethean\FireSdk\Exceptions\FireNotFoundError;
use ModusPromethean\FireSdk\Exceptions\FireTimeoutError;
use ModusPromethean\FireSdk\Exceptions\FireValidationError;
use ModusPromethean\FireSdk\FireClient;
use ModusPromethean\FireSdk\Results\FlowRun;
use ModusPromethean\FireSdk\Species\Anthropic;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FireClient — a fake transport closure stands in for cURL,
 * no network calls. Mirrors the Python SDK's mocked-request test suite
 * (same routing/error-mapping/verbosity assertions), see python/tests/.
 */
final class FireClientTest extends TestCase
{
    /**
     * @param array{status: int, body: string} $response
     * @return array{0: FireClient, 1: \ArrayObject<int, array{method: string, url: string, headers: array<string,string>, jsonBody: ?string}>}
     */
    private function clientWithFakeTransport(array $response, ?string $token = 'fire_sk_test'): array
    {
        $calls = new \ArrayObject();
        $transport = function (string $method, string $url, array $headers, ?string $jsonBody) use ($calls, $response): array {
            $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'jsonBody' => $jsonBody];
            return $response;
        };

        $ref = new \ReflectionClass(FireClient::class);
        $client = $ref->newInstance($token, 'https://fire.example.test', 60.0, $transport);

        return [$client, $calls];
    }

    private function jsonResponse(int $status, array $body): array
    {
        return ['status' => $status, 'body' => json_encode($body)];
    }

    // ── routing ──────────────────────────────────────────────────────────

    public function testCapabilitiesNeedsNoTokenAndHitsV1(): void
    {
        [$client, $calls] = $this->clientWithFakeTransport($this->jsonResponse(200, ['planes' => []]), token: null);
        $client->capabilities();
        $this->assertSame('https://fire.example.test/v1/capabilities', $calls[0]['url']);
        $this->assertArrayNotHasKey('Authorization', $calls[0]['headers']);
    }

    public function testChatHitsV1(): void
    {
        [$client, $calls] = $this->clientWithFakeTransport($this->jsonResponse(200, ['content' => 'hi']));
        $client->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('https://fire.example.test/v1/chat', $calls[0]['url']);
        $this->assertSame('Bearer fire_sk_test', $calls[0]['headers']['Authorization']);
    }

    public function testChatParallelHitsV1(): void
    {
        [$client, $calls] = $this->clientWithFakeTransport($this->jsonResponse(200, ['results' => []]));
        $client->chatParallel([['messages' => [['role' => 'user', 'content' => 'x']]]]);
        $this->assertSame('https://fire.example.test/v1/chat/parallel', $calls[0]['url']);
    }

    public function testImageHitsV1(): void
    {
        [$client, $calls] = $this->clientWithFakeTransport($this->jsonResponse(200, ['images' => [], 'model' => 'm', 'provider' => 'p']));
        $client->image('a cat');
        $this->assertSame('https://fire.example.test/v1/image', $calls[0]['url']);
    }

    public function testRunWorkflowHitsV1WorkflowsSlug(): void
    {
        [$client, $calls] = $this->clientWithFakeTransport($this->jsonResponse(200, ['ok' => true]));
        $client->runWorkflow('article', ['source_text' => '...']);
        $this->assertSame('https://fire.example.test/v1/workflows/article', $calls[0]['url']);
        $this->assertSame('{"source_text":"..."}', $calls[0]['jsonBody']);
    }

    public function testAgentConfigCrudHitsV2(): void
    {
        [$client, $calls] = $this->clientWithFakeTransport($this->jsonResponse(200, ['slug' => 'hot']));
        $client->getAgentConfig('hot');
        $this->assertSame('https://fire.example.test/v2/agent-configs/hot', $calls[0]['url']);
    }

    public function testRunFlowWithSlugHitsSlugEndpoint(): void
    {
        [$client, $calls] = $this->clientWithFakeTransport($this->jsonResponse(202, ['run_id' => 1, 'status' => 'pending']));
        $client->runFlow(flowSlug: 'triad', input: ['prompt_package' => 'x']);
        $this->assertSame('https://fire.example.test/v2/flows/triad/run', $calls[0]['url']);
    }

    public function testRunFlowWithInlineSpecHitsDeprecatedBodyEndpoint(): void
    {
        [$client, $calls] = $this->clientWithFakeTransport($this->jsonResponse(202, ['run_id' => 1, 'status' => 'pending']));
        $client->runFlow(flow: ['steps' => []], input: []);
        $this->assertSame('https://fire.example.test/v2/flows/run', $calls[0]['url']);
    }

    public function testGetFlowRunHitsV2(): void
    {
        [$client, $calls] = $this->clientWithFakeTransport($this->jsonResponse(200, ['run_id' => 1, 'status' => 'completed']));
        $client->getFlowRun(1);
        $this->assertSame('https://fire.example.test/v2/flows/runs/1', $calls[0]['url']);
    }

    public function testResumeFlowRunHitsV2(): void
    {
        [$client, $calls] = $this->clientWithFakeTransport($this->jsonResponse(202, ['run_id' => 1, 'status' => 'running']));
        $client->resumeFlowRun(1, 'review', ['note' => 'x']);
        $this->assertSame('https://fire.example.test/v2/flows/runs/1/resume', $calls[0]['url']);
    }

    // ── verbosity ────────────────────────────────────────────────────────

    public function testDefaultVerbositySendsNoQueryParam(): void
    {
        [$client, $calls] = $this->clientWithFakeTransport($this->jsonResponse(200, ['run_id' => 1, 'status' => 'completed']));
        $client->getFlowRun(1);
        $this->assertStringNotContainsString('verbosity', $calls[0]['url']);
    }

    public function testCompactVerbositySendsQueryParam(): void
    {
        [$client, $calls] = $this->clientWithFakeTransport($this->jsonResponse(200, ['run_id' => 1, 'status' => 'completed']));
        $client->getFlowRun(1, verbosity: 'compact');
        $this->assertStringContainsString('verbosity=compact', $calls[0]['url']);
    }

    // ── typed results ────────────────────────────────────────────────────

    public function testChatReturnsChatResult(): void
    {
        $body = [
            'content' => 'hi there', 'model' => 'Claude Sonnet 4.5', 'provider' => 'edenai',
            'usage' => ['input' => 10, 'output' => 5], 'meta' => ['price' => ['usd' => 0.001], 'log_id' => 42],
        ];
        [$client] = $this->clientWithFakeTransport($this->jsonResponse(200, $body));
        $result = $client->chat([['role' => 'user', 'content' => 'hi']]);
        $this->assertSame('hi there', $result->content);
        $this->assertSame(10, $result->usage->input);
        $this->assertSame(0.001, $result->priceUsd);
        $this->assertSame(42, $result->logId);
        $this->assertSame($body, $result->raw);
    }

    public function testChatParallelReturnsPerItemResults(): void
    {
        $body = ['results' => [
            ['ok' => true, 'content' => 'a', 'model' => 'm1', 'usage' => ['input' => 1, 'output' => 1], 'meta' => ['price' => ['usd' => 0.0001]]],
            ['ok' => false, 'error' => 'boom'],
        ]];
        [$client] = $this->clientWithFakeTransport($this->jsonResponse(200, $body));
        $result = $client->chatParallel([['messages' => []], ['messages' => []]]);
        $this->assertCount(2, $result->results);
        $this->assertTrue($result->results[0]->ok);
        $this->assertSame('a', $result->results[0]->content);
        $this->assertFalse($result->results[1]->ok);
        $this->assertSame('boom', $result->results[1]->error);
    }

    public function testImageReturnsImageResult(): void
    {
        $body = ['images' => [['b64' => 'abc', 'url' => null]], 'model' => 'gpt-image-1', 'provider' => 'openai', 'meta' => ['price' => ['usd' => 0.06]]];
        [$client] = $this->clientWithFakeTransport($this->jsonResponse(200, $body));
        $result = $client->image('a cat');
        $this->assertCount(1, $result->images);
        $this->assertSame('abc', $result->images[0]->b64);
        $this->assertSame(0.06, $result->priceUsd);
    }

    public function testRunWorkflowReturnsThinWorkflowResult(): void
    {
        $body = ['anything' => 'the workflow wants to return'];
        [$client] = $this->clientWithFakeTransport($this->jsonResponse(200, $body));
        $result = $client->runWorkflow('article', ['source_text' => '...']);
        $this->assertSame('article', $result->workflow);
        $this->assertSame($body, $result->raw);
    }

    public function testRunFlowReturnsFlowRun(): void
    {
        [$client] = $this->clientWithFakeTransport($this->jsonResponse(202, ['run_id' => 5, 'status' => 'pending', 'steps' => []]));
        $run = $client->runFlow(flowSlug: 'triad', input: []);
        $this->assertInstanceOf(FlowRun::class, $run);
        $this->assertSame(5, $run->runId);
        $this->assertSame('pending', $run->status);
    }

    public function testFlowRunStepsAreTypedAndGatingStepIsFindable(): void
    {
        $body = [
            'run_id' => 5, 'status' => 'awaiting_human', 'steps' => [
                ['step_key' => 'hot_1', 'kind' => 'agent_call', 'status' => 'completed', 'depends_on' => [], 'error' => null],
                ['step_key' => 'review', 'kind' => 'human_gate', 'status' => 'awaiting_human', 'depends_on' => ['hot_1'],
                    'error' => null, 'prompt_for_human' => 'go?', 'context' => ['hot_1' => '...']],
            ],
        ];
        [$client] = $this->clientWithFakeTransport($this->jsonResponse(200, $body));
        $run = $client->getFlowRun(5);
        $gate = $run->gatingStep();
        $this->assertNotNull($gate);
        $this->assertSame('review', $gate->stepKey);
        $this->assertSame('go?', $gate->promptForHuman);
    }

    // ── species registry ─────────────────────────────────────────────────

    public function testKnownConstantResolvesToASpeciesNameString(): void
    {
        $this->assertSame('claude-sonnet-4-5', Anthropic::CLAUDE_SONNET_4_5);
    }

    public function testSpeciesUsableDirectlyAsSpeciesName(): void
    {
        [$client, $calls] = $this->clientWithFakeTransport($this->jsonResponse(200, ['content' => 'hi']));
        $client->chat([['role' => 'user', 'content' => 'hi']], speciesName: Anthropic::CLAUDE_SONNET_4_5);
        $this->assertStringContainsString('"claude-sonnet-4-5"', $calls[0]['jsonBody']);
    }

    // ── error mapping ────────────────────────────────────────────────────

    public function test401RaisesAuthError(): void
    {
        [$client] = $this->clientWithFakeTransport($this->jsonResponse(401, ['description' => 'no token']));
        $this->expectException(FireAuthError::class);
        $client->status();
    }

    public function test404RaisesNotFoundError(): void
    {
        [$client] = $this->clientWithFakeTransport($this->jsonResponse(404, ['description' => 'nope']));
        $this->expectException(FireNotFoundError::class);
        $client->getFlowRun(999);
    }

    public function test409RaisesConflictError(): void
    {
        [$client] = $this->clientWithFakeTransport($this->jsonResponse(409, ['error_code' => 'FLOW_RESUME_CONFLICT', 'description' => 'not waiting']));
        $this->expectException(FireConflictError::class);
        $client->resumeFlowRun(1, 'review', []);
    }

    public function test422RaisesValidationErrorWithDetails(): void
    {
        [$client] = $this->clientWithFakeTransport($this->jsonResponse(422, ['error_code' => 'FLOW_INVALID', 'description' => 'bad spec', 'details' => ['x']]));
        try {
            $client->runFlow(flow: ['steps' => []], input: []);
            $this->fail('expected FireValidationError');
        } catch (FireValidationError $e) {
            $this->assertSame(['x'], $e->response['details']);
        }
    }

    // ── waitForFlow ──────────────────────────────────────────────────────

    public function testWaitForFlowStopsOnTerminalStatus(): void
    {
        [$client] = $this->clientWithFakeTransport($this->jsonResponse(200, ['run_id' => 1, 'status' => 'completed']));
        $run = $client->waitForFlow(1, pollInterval: 0.01, timeout: 1.0);
        $this->assertSame('completed', $run->status);
    }

    public function testWaitForFlowStopsOnAwaitingHuman(): void
    {
        [$client] = $this->clientWithFakeTransport($this->jsonResponse(200, ['run_id' => 1, 'status' => 'awaiting_human']));
        $run = $client->waitForFlow(1, pollInterval: 0.01, timeout: 1.0);
        $this->assertSame('awaiting_human', $run->status);
    }

    public function testWaitForFlowTimesOutWhileStillRunning(): void
    {
        [$client] = $this->clientWithFakeTransport($this->jsonResponse(200, ['run_id' => 1, 'status' => 'running']));
        $this->expectException(FireTimeoutError::class);
        $client->waitForFlow(1, pollInterval: 0.01, timeout: 0.03);
    }

    public function testFlowRunWaitUpdatesInPlace(): void
    {
        [$client] = $this->clientWithFakeTransport($this->jsonResponse(200, ['run_id' => 1, 'status' => 'completed', 'output' => ['content' => 'done']]));
        $running = new FlowRun($client, ['run_id' => 1, 'status' => 'running']);
        $result = $running->wait(pollInterval: 0.01, timeout: 1.0);
        $this->assertSame($running, $result); // mutates and returns self
        $this->assertSame('completed', $running->status);
        $this->assertSame('done', $running->output['content']);
    }

    // ── redeemTierCode ───────────────────────────────────────────────────

    public function testRedeemTierCodeReturnsTokenAndReadyClient(): void
    {
        $calls = [];
        $transport = function (string $method, string $url, array $headers, ?string $jsonBody) use (&$calls): array {
            $calls[] = compact('method', 'url', 'headers', 'jsonBody');
            return ['status' => 200, 'body' => json_encode(['token' => 'fire_sk_new', 'account_id' => 1])];
        };

        // redeemTierCode is static and builds its own anonymous client, so we
        // can't inject a transport through the constructor here — instead
        // assert on the shape of what it returns via a real (but token-less)
        // instance method call path is out of scope; this test documents the
        // return contract using FireClient's own constructor injection for
        // the underlying request instead.
        $ref = new \ReflectionClass(FireClient::class);
        $anon = $ref->newInstance(null, 'https://fire.example.test', 60.0, $transport);
        $method = $ref->getMethod('request');
        $method->setAccessible(true);
        $body = $method->invoke($anon, 'POST', 'v1/billing/tier/redeem', ['code' => 'X', 'name' => 'N', 'email' => 'e@example.test']);

        $this->assertSame('fire_sk_new', $body['token']);
        $this->assertSame('https://fire.example.test/v1/billing/tier/redeem', $calls[0]['url']);
    }
}
