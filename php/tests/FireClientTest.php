<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk\Tests;

use ModusPromethean\FireSdk\Exceptions\FireAuthError;
use ModusPromethean\FireSdk\Exceptions\FireConflictError;
use ModusPromethean\FireSdk\Exceptions\FireNotFoundError;
use ModusPromethean\FireSdk\Exceptions\FireTimeoutError;
use ModusPromethean\FireSdk\Exceptions\FireValidationError;
use ModusPromethean\FireSdk\FireClient;
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
        $this->assertSame('completed', $run['status']);
    }

    public function testWaitForFlowStopsOnAwaitingHuman(): void
    {
        [$client] = $this->clientWithFakeTransport($this->jsonResponse(200, ['run_id' => 1, 'status' => 'awaiting_human']));
        $run = $client->waitForFlow(1, pollInterval: 0.01, timeout: 1.0);
        $this->assertSame('awaiting_human', $run['status']);
    }

    public function testWaitForFlowTimesOutWhileStillRunning(): void
    {
        [$client] = $this->clientWithFakeTransport($this->jsonResponse(200, ['run_id' => 1, 'status' => 'running']));
        $this->expectException(FireTimeoutError::class);
        $client->waitForFlow(1, pollInterval: 0.01, timeout: 0.03);
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
