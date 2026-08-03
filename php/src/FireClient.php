<?php

declare(strict_types=1);

namespace ModusPromethean\FireSdk;

use ModusPromethean\FireSdk\Exceptions\FireAuthError;
use ModusPromethean\FireSdk\Exceptions\FireBillingError;
use ModusPromethean\FireSdk\Exceptions\FireConflictError;
use ModusPromethean\FireSdk\Exceptions\FireError;
use ModusPromethean\FireSdk\Exceptions\FireNotFoundError;
use ModusPromethean\FireSdk\Exceptions\FireServerError;
use ModusPromethean\FireSdk\Exceptions\FireTimeoutError;
use ModusPromethean\FireSdk\Exceptions\FireValidationError;

/**
 * A Fire API client bound to one token. Covers L1 (raw chat calls) and v2
 * (data-driven, resumable multi-agent flows). Every method returns the
 * parsed JSON response as a plain associative array — deliberately no
 * custom response objects, mirroring the Python SDK's own design choice.
 */
class FireClient
{
    public const DEFAULT_BASE_URL = 'https://fire.test1.prosaga.net';

    /** Terminal flow-run statuses — waitForFlow() stops polling on any of these. */
    private const FLOW_TERMINAL_STATUSES = ['completed', 'failed', 'cancelled', 'awaiting_human'];

    private readonly string $baseUrl;

    /** @var (callable(string, string, array<string, string>, ?string): array{status: int, body: string})|null */
    private $transport;

    /**
     * @param string|null $token A `fire_sk_...` bearer token. Omit only if you
     *     intend to call capabilities() or redeemTierCode(), the two
     *     endpoints that don't require one.
     * @param string $baseUrl Root URL, no version suffix — v1 and v2 are
     *     sibling path prefixes, not nested (`.../v1/chat`, `.../v2/flows/run`
     *     on the same host). Defaults to Fire's test1 environment; pass
     *     `https://fire.prosaga.net` once your token/flows are promoted to prod.
     * @param float $timeout Per-request timeout in seconds.
     * @param (callable(string, string, array<string, string>, ?string): array{status: int, body: string})|null $transport
     *     Internal — lets tests substitute the real cURL call with a fake
     *     one. Not for normal use.
     */
    public function __construct(
        private readonly ?string $token = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = 60.0,
        ?callable $transport = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport;
    }

    // ── internals ───────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function headers(): array
    {
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if ($this->token !== null) {
            $headers['Authorization'] = "Bearer {$this->token}";
        }
        return $headers;
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, mixed>|null $query
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $json = null, ?array $query = null): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query !== null && $query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $jsonBody = $json !== null ? json_encode($json, JSON_THROW_ON_ERROR) : null;

        $result = $this->transport !== null
            ? ($this->transport)($method, $url, $this->headers(), $jsonBody)
            : $this->curlTransport($method, $url, $this->headers(), $jsonBody);

        $statusCode = $result['status'];
        $raw = $result['body'];
        $body = $raw === '' ? [] : (json_decode($raw, true) ?? ['raw' => $raw]);

        if ($statusCode >= 200 && $statusCode < 300) {
            return $body;
        }

        $this->raiseForStatus($statusCode, $body);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private function curlTransport(string $method, string $url, array $headers, ?string $jsonBody): array
    {
        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = "{$key}: {$value}";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ceil($this->timeout),
        ]);
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new FireError("Request to {$url} failed: {$error}");
        }
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $statusCode, 'body' => $raw];
    }

    /**
     * @param array<string, mixed> $body
     * @return never
     */
    private function raiseForStatus(int $statusCode, array $body): void
    {
        $errorCode = $body['error_code'] ?? null;
        $message = $body['description'] ?? $body['error'] ?? $body['message'] ?? json_encode($body);

        $args = [$message, $statusCode, $errorCode, $body];

        if ($statusCode === 401) {
            throw new FireAuthError(...$args);
        }
        if ($statusCode === 402 || in_array($errorCode, ['PRICING_TIER_UNAVAILABLE', 'INSUFFICIENT_BALANCE'], true)) {
            throw new FireBillingError(...$args);
        }
        if ($statusCode === 404) {
            throw new FireNotFoundError(...$args);
        }
        if ($statusCode === 409) {
            throw new FireConflictError(...$args);
        }
        if ($statusCode === 422) {
            throw new FireValidationError(...$args);
        }
        if ($statusCode >= 500) {
            throw new FireServerError(...$args);
        }
        throw new FireError(...$args);
    }

    // ── service discovery / diagnostics ─────────────────────────────────

    /**
     * GET /capabilities — no auth required. Start here to confirm which
     * planes (data/flows/billing/...) and models are live before assuming
     * anything about the API surface.
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        return $this->request('GET', 'v1/capabilities');
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return $this->request('GET', 'v1/status');
    }

    /**
     * Every active, onboarded model deployment, including each species'
     * `strengths` (e.g. "reasoning") and `capabilities`.
     * @return array<string, mixed>
     */
    public function models(): array
    {
        return $this->request('GET', 'v1/models');
    }

    /** @return array<string, mixed> */
    public function usage(): array
    {
        return $this->request('GET', 'v1/usage');
    }

    // ── L1 — chat ────────────────────────────────────────────────────────

    /**
     * POST /chat — a single chat completion.
     *
     * Note: for models tagged "reasoning" (see `strengths` in models()),
     * Fire silently raises a too-low maxTokens to a safe floor
     * server-side — these models spend part of the budget on hidden
     * reasoning before any visible output.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed>|null $options
     * @return array<string, mixed>
     */
    public function chat(
        array $messages,
        ?string $systemPrompt = null,
        ?string $speciesName = null,
        float $temperature = 0.7,
        int $maxTokens = 1024,
        ?array $tags = null,
        ?array $options = null,
    ): array {
        $body = ['messages' => $messages, 'temperature' => $temperature, 'max_tokens' => $maxTokens];
        if ($systemPrompt !== null) $body['system_prompt'] = $systemPrompt;
        if ($speciesName !== null) $body['species_name'] = $speciesName;
        if ($tags !== null) $body['tags'] = $tags;
        if ($options !== null) $body['options'] = $options;

        return $this->request('POST', 'v1/chat', json: $body);
    }

    /**
     * POST /chat/parallel — up to 10 independent chat requests run
     * concurrently, each item accepting the same fields as chat(). No
     * synthesis step — use a v2 flow if you need one call to see the
     * others' outputs.
     *
     * @param list<array<string, mixed>> $requests
     * @return array<string, mixed>
     */
    public function chatParallel(array $requests): array
    {
        return $this->request('POST', 'v1/chat/parallel', json: ['requests' => $requests]);
    }

    // ── v2 — agent configs (reusable model+temperature+system_prompt) ──

    /** @return array<string, mixed> */
    public function createAgentConfig(
        string $slug,
        string $label,
        ?string $speciesName = null,
        ?string $systemPrompt = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?string $roleTag = null,
        ?array $options = null,
    ): array {
        $body = ['slug' => $slug, 'label' => $label];
        foreach ([
            'species_name' => $speciesName,
            'system_prompt' => $systemPrompt,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'role_tag' => $roleTag,
            'options' => $options,
        ] as $key => $value) {
            if ($value !== null) $body[$key] = $value;
        }
        return $this->request('POST', 'v2/agent-configs', json: $body);
    }

    /** @return array<string, mixed> */
    public function getAgentConfig(string $slug): array
    {
        return $this->request('GET', "v2/agent-configs/{$slug}");
    }

    /** @return array<string, mixed> */
    public function listAgentConfigs(): array
    {
        return $this->request('GET', 'v2/agent-configs');
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function updateAgentConfig(string $slug, array $fields): array
    {
        return $this->request('PUT', "v2/agent-configs/{$slug}", json: $fields);
    }

    /** @return array<string, mixed> */
    public function deleteAgentConfig(string $slug): array
    {
        return $this->request('DELETE', "v2/agent-configs/{$slug}");
    }

    // ── v2 — flow definitions (reusable named DAG templates) ────────────

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    /**
     * Defaults to a public, discoverable flow (`GET /v2/flows` lists it,
     * any token can run it) unless `$isPublic = false` — your token's
     * account owns it either way, and only the owner can edit or
     * deactivate it regardless of visibility.
     */
    public function createFlowDefinition(string $slug, string $label, array $spec, ?string $description = null, ?bool $isPublic = null): array
    {
        $body = ['slug' => $slug, 'label' => $label, 'spec' => $spec];
        if ($description !== null) $body['description'] = $description;
        if ($isPublic !== null) $body['is_public'] = $isPublic;
        return $this->request('POST', 'v2/flows', json: $body);
    }

    /** @return array<string, mixed> */
    public function getFlowDefinition(string $slug): array
    {
        return $this->request('GET', "v2/flows/{$slug}");
    }

    /** @return array<string, mixed> */
    public function listFlowDefinitions(): array
    {
        return $this->request('GET', 'v2/flows');
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function updateFlowDefinition(string $slug, array $fields): array
    {
        return $this->request('PUT', "v2/flows/{$slug}", json: $fields);
    }

    /** @return array<string, mixed> */
    public function deleteFlowDefinition(string $slug): array
    {
        return $this->request('DELETE', "v2/flows/{$slug}");
    }

    // ── v2 — flow runs ───────────────────────────────────────────────────

    /**
     * Start a flow run and return immediately (execution is always
     * queued; use waitForFlow() or poll getFlowRun() yourself for the
     * result).
     *
     * Pass exactly one of $flowSlug (a saved flow, e.g. "triad") or $flow
     * (a full inline `{"steps": [...]}` spec).
     *
     * $flowSlug calls `POST /v2/flows/{slug}/run` — the slug itself is the
     * endpoint, same shape as L1's `POST /v1/workflows/{workflow}`. $flow
     * (an unsaved/ad-hoc spec) has no slug to route on and still calls
     * `POST /v2/flows/run`, the only path that accepts an inline spec.
     *
     * @param array<string, mixed>|null $flow
     * @param array<string, mixed>|null $input
     * @return array<string, mixed>
     */
    public function runFlow(
        ?string $flowSlug = null,
        ?array $flow = null,
        ?array $input = null,
        ?int $conversationId = null,
        ?string $userMessage = null,
        string $verbosity = 'full',
    ): array {
        if ($flowSlug === null && $flow === null) {
            throw new \InvalidArgumentException('runFlow() requires flowSlug or flow');
        }

        $body = ['input' => $input ?? new \stdClass()];
        if ($conversationId !== null) $body['conversation_id'] = $conversationId;
        if ($userMessage !== null) $body['user_message'] = $userMessage;

        $query = $verbosity !== 'full' ? ['verbosity' => $verbosity] : null;

        if ($flowSlug !== null) {
            return $this->request('POST', "v2/flows/{$flowSlug}/run", json: $body, query: $query);
        }

        $body['flow'] = $flow;
        return $this->request('POST', 'v2/flows/run', json: $body, query: $query);
    }

    /** @return array<string, mixed> */
    public function getFlowRun(int $runId, string $verbosity = 'full'): array
    {
        $query = $verbosity !== 'full' ? ['verbosity' => $verbosity] : null;
        return $this->request('GET', "v2/flows/runs/{$runId}", query: $query);
    }

    /** @return array<string, mixed> */
    public function listFlowRuns(?int $conversationId = null, ?string $status = null, int $perPage = 25): array
    {
        $query = ['per_page' => $perPage];
        if ($conversationId !== null) $query['conversation_id'] = $conversationId;
        if ($status !== null) $query['status'] = $status;
        return $this->request('GET', 'v2/flows/runs', query: $query);
    }

    /**
     * POST /flows/runs/{run}/resume — supply human input for the step
     * currently gating a paused run (status === "awaiting_human"). Throws
     * FireConflictError if the run isn't actually waiting, or $stepKey
     * doesn't match the current gate.
     *
     * @param array<string, mixed> $humanInput
     * @return array<string, mixed>
     */
    public function resumeFlowRun(int $runId, string $stepKey, array $humanInput, string $verbosity = 'full'): array
    {
        $query = $verbosity !== 'full' ? ['verbosity' => $verbosity] : null;
        $body = ['step_key' => $stepKey, 'human_input' => $humanInput];
        return $this->request('POST', "v2/flows/runs/{$runId}/resume", json: $body, query: $query);
    }

    /**
     * Poll getFlowRun() until it lands on a terminal status — completed,
     * failed, cancelled, or awaiting_human (a human gate is a legitimate
     * stopping point, not a failure; check result['status'] and, if
     * "awaiting_human", call resumeFlowRun() with the gating step's
     * step_key).
     *
     * @throws FireTimeoutError if the run is still pending/running after $timeout seconds.
     * @return array<string, mixed>
     */
    public function waitForFlow(int $runId, float $pollInterval = 1.5, float $timeout = 120.0, string $verbosity = 'full'): array
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $run = $this->getFlowRun($runId, verbosity: $verbosity);
            if (in_array($run['status'], self::FLOW_TERMINAL_STATUSES, true)) {
                return $run;
            }
            if (microtime(true) >= $deadline) {
                throw new FireTimeoutError("flow run {$runId} still '{$run['status']}' after {$timeout}s");
            }
            usleep((int) ($pollInterval * 1_000_000));
        }
    }

    // ── v2 — self-service tier onboarding ───────────────────────────────

    /**
     * POST /billing/tier/redeem — trade a JP-issued code for a real
     * token, no existing token required. Returns [rawToken, responseBody,
     * client] where $client is a ready FireClient already carrying the
     * new token.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: FireClient}
     */
    public static function redeemTierCode(
        string $code,
        string $name,
        string $email,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = 60.0,
    ): array {
        $anon = new self(token: null, baseUrl: $baseUrl, timeout: $timeout);
        $body = $anon->request('POST', 'v1/billing/tier/redeem', json: [
            'code' => $code, 'name' => $name, 'email' => $email,
        ]);
        $token = $body['token'];
        return [$token, $body, new self(token: $token, baseUrl: $baseUrl, timeout: $timeout)];
    }
}
