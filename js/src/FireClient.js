import {
  FireAuthError,
  FireBillingError,
  FireConflictError,
  FireError,
  FireNotFoundError,
  FireServerError,
  FireTimeoutError,
  FireValidationError,
} from './errors.js';
import { ChatResult, FlowRun, ImageResult, ParallelChatResult, WorkflowResult } from './results.js';

export const DEFAULT_BASE_URL = 'https://fire.test1.prosaga.net';

/**
 * A Fire API client bound to one token. Covers L1 (chat/image), L2
 * (workflows), and v2 (data-driven, resumable multi-agent flows).
 * Result-bearing calls return typed objects (see results.js) — three
 * separate root families matching the three layers Fire itself enforces
 * server-side, not one shared hierarchy. Discovery/introspection
 * endpoints (capabilities, status, models, usage) and CRUD metadata
 * (agent-configs, flow-definitions) stay plain objects on purpose:
 * they're heterogeneous, evolving documents, not the result of an action.
 */
export class FireClient {
  /**
   * @param {object} [opts]
   * @param {string|null} [opts.token] A `fire_sk_...` bearer token. Omit only
   *   if you intend to call capabilities() or FireClient.redeemTierCode(),
   *   the two endpoints that don't require one.
   * @param {string} [opts.baseUrl] Root URL, no version suffix — v1 and v2
   *   are sibling path prefixes, not nested (`.../v1/chat`,
   *   `.../v2/flows/run` on the same host). Defaults to Fire's test1
   *   environment; pass `https://fire.prosaga.net` once your token/flows
   *   are promoted to prod.
   * @param {number} [opts.timeout] Per-request timeout in milliseconds.
   * @param {typeof fetch} [opts.fetchImpl] Internal — lets tests substitute
   *   a fake fetch. Not for normal use.
   */
  constructor({ token = null, baseUrl = DEFAULT_BASE_URL, timeout = 60_000, fetchImpl = fetch } = {}) {
    this.token = token;
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.timeout = timeout;
    this._fetch = fetchImpl;
  }

  // ── internals ───────────────────────────────────────────────────────

  _headers() {
    const headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
    if (this.token) headers.Authorization = `Bearer ${this.token}`;
    return headers;
  }

  /**
   * @param {string} method
   * @param {string} path
   * @param {{json?: object, query?: Record<string, string|number>}} [opts]
   */
  async _request(method, path, { json, query } = {}) {
    let url = `${this.baseUrl}/${path.replace(/^\/+/, '')}`;
    if (query && Object.keys(query).length > 0) {
      url += `?${new URLSearchParams(query).toString()}`;
    }

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeout);

    let response;
    try {
      response = await this._fetch(url, {
        method,
        headers: this._headers(),
        body: json !== undefined ? JSON.stringify(json) : undefined,
        signal: controller.signal,
      });
    } finally {
      clearTimeout(timer);
    }

    const text = await response.text();
    let body;
    try {
      body = text ? JSON.parse(text) : {};
    } catch {
      body = { raw: text };
    }

    if (response.ok) return body;

    this._raiseForStatus(response.status, body);
  }

  _raiseForStatus(statusCode, body) {
    const errorCode = body.error_code ?? null;
    const message = body.description ?? body.error ?? body.message ?? JSON.stringify(body);
    const opts = { statusCode, errorCode, response: body };

    if (statusCode === 401) throw new FireAuthError(message, opts);
    if (statusCode === 402 || errorCode === 'PRICING_TIER_UNAVAILABLE' || errorCode === 'INSUFFICIENT_BALANCE') {
      throw new FireBillingError(message, opts);
    }
    if (statusCode === 404) throw new FireNotFoundError(message, opts);
    if (statusCode === 409) throw new FireConflictError(message, opts);
    if (statusCode === 422) throw new FireValidationError(message, opts);
    if (statusCode >= 500) throw new FireServerError(message, opts);
    throw new FireError(message, opts);
  }

  // ── service discovery / diagnostics ─────────────────────────────────

  /** GET /capabilities — no auth required. Start here to confirm which
   * planes (data/flows/billing/...) and models are live before assuming
   * anything about the API surface. */
  capabilities() {
    return this._request('GET', 'v1/capabilities');
  }

  status() {
    return this._request('GET', 'v1/status');
  }

  /** Every active, onboarded model deployment, including each species'
   * `strengths` (e.g. "reasoning") and `capabilities`. */
  models() {
    return this._request('GET', 'v1/models');
  }

  usage() {
    return this._request('GET', 'v1/usage');
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
   * @param {{role: string, content: string}[]} messages
   * @param {object} [opts]
   */
  async chat(messages, { systemPrompt, speciesName, temperature = 0.7, maxTokens = 1024, tags, options } = {}) {
    const body = { messages, temperature, max_tokens: maxTokens };
    if (systemPrompt !== undefined) body.system_prompt = systemPrompt;
    if (speciesName !== undefined) body.species_name = speciesName;
    if (tags !== undefined) body.tags = tags;
    if (options !== undefined) body.options = options;
    return ChatResult.fromRaw(await this._request('POST', 'v1/chat', { json: body }));
  }

  /** POST /chat/parallel — up to 10 independent chat requests run
   * concurrently, each item accepting the same fields as chat(). No
   * synthesis step — use a v2 flow if you need one call to see the
   * others' outputs.
   * @param {object[]} requests */
  async chatParallel(requests) {
    return ParallelChatResult.fromRaw(await this._request('POST', 'v1/chat/parallel', { json: { requests } }));
  }

  // ── L1 — image ──────────────────────────────────────────────────────

  /** POST /image — generate images from a text prompt. */
  async image(prompt, { speciesName, n = 1, size = '1024x1024' } = {}) {
    const body = { prompt, n, size };
    if (speciesName !== undefined) body.species_name = speciesName;
    return ImageResult.fromRaw(await this._request('POST', 'v1/image', { json: body }));
  }

  // ── L2 — workflows ───────────────────────────────────────────────────

  /**
   * POST /workflows/{slug} — a canned, server-side, multi-step recipe
   * (e.g. "image", "article"). Each workflow defines its own request/
   * response contract — pass whatever fields that workflow expects in
   * `params`; see API.md's Layer 2 section for each one's shape. The
   * response is deliberately thin (see results.js's WorkflowResult) —
   * use `.raw` for the workflow-specific fields.
   */
  async runWorkflow(workflowSlug, params = {}) {
    return WorkflowResult.fromRaw(workflowSlug, await this._request('POST', `v1/workflows/${workflowSlug}`, { json: params }));
  }

  // ── v2 — agent configs (reusable model+temperature+system_prompt) ──

  createAgentConfig(slug, label, { speciesName, systemPrompt, temperature, maxTokens, roleTag, options } = {}) {
    const body = { slug, label };
    const extra = {
      species_name: speciesName,
      system_prompt: systemPrompt,
      temperature,
      max_tokens: maxTokens,
      role_tag: roleTag,
      options,
    };
    for (const [key, value] of Object.entries(extra)) {
      if (value !== undefined) body[key] = value;
    }
    return this._request('POST', 'v2/agent-configs', { json: body });
  }

  getAgentConfig(slug) {
    return this._request('GET', `v2/agent-configs/${slug}`);
  }

  listAgentConfigs() {
    return this._request('GET', 'v2/agent-configs');
  }

  updateAgentConfig(slug, fields) {
    return this._request('PUT', `v2/agent-configs/${slug}`, { json: fields });
  }

  deleteAgentConfig(slug) {
    return this._request('DELETE', `v2/agent-configs/${slug}`);
  }

  // ── v2 — flow definitions (reusable named DAG templates) ────────────

  /**
   * Defaults to a public, discoverable flow (`GET /v2/flows` lists it,
   * any token can run it) unless `isPublic: false` — your token's
   * account owns it either way, and only the owner can edit or
   * deactivate it regardless of visibility.
   */
  createFlowDefinition(slug, label, spec, { description, isPublic } = {}) {
    const body = { slug, label, spec };
    if (description !== undefined) body.description = description;
    if (isPublic !== undefined) body.is_public = isPublic;
    return this._request('POST', 'v2/flows', { json: body });
  }

  getFlowDefinition(slug) {
    return this._request('GET', `v2/flows/${slug}`);
  }

  listFlowDefinitions() {
    return this._request('GET', 'v2/flows');
  }

  updateFlowDefinition(slug, fields) {
    return this._request('PUT', `v2/flows/${slug}`, { json: fields });
  }

  deleteFlowDefinition(slug) {
    return this._request('DELETE', `v2/flows/${slug}`);
  }

  // ── v2 — flow runs ───────────────────────────────────────────────────

  /**
   * Start a flow run and return immediately (execution is always queued;
   * use waitForFlow() or poll getFlowRun() yourself for the result).
   *
   * Pass exactly one of `flowSlug` (a saved flow, e.g. "triad") or `flow`
   * (a full inline `{steps: [...]}` spec).
   *
   * `flowSlug` calls `POST /v2/flows/{slug}/run` — the slug itself is the
   * endpoint, same shape as L1's `POST /v1/workflows/{workflow}`. `flow`
   * (an unsaved/ad-hoc spec) has no slug to route on and still calls
   * `POST /v2/flows/run`, the only path that accepts an inline spec.
   */
  async runFlow({ flowSlug, flow, input, conversationId, userMessage, verbosity = 'full' } = {}) {
    if (!flowSlug && !flow) {
      throw new TypeError('runFlow() requires flowSlug or flow');
    }

    const body = { input: input ?? {} };
    if (conversationId !== undefined) body.conversation_id = conversationId;
    if (userMessage !== undefined) body.user_message = userMessage;

    const query = verbosity !== 'full' ? { verbosity } : undefined;

    if (flowSlug) {
      return new FlowRun(this, await this._request('POST', `v2/flows/${flowSlug}/run`, { json: body, query }));
    }

    body.flow = flow;
    return new FlowRun(this, await this._request('POST', 'v2/flows/run', { json: body, query }));
  }

  async getFlowRun(runId, { verbosity = 'full' } = {}) {
    const query = verbosity !== 'full' ? { verbosity } : undefined;
    return new FlowRun(this, await this._request('GET', `v2/flows/runs/${runId}`, { query }));
  }

  /**
   * Returns `{ runs: FlowRun[], meta: {...} }` — the outer envelope
   * (pagination) stays a plain object since it's list-specific, not a
   * result in its own right; each run inside is a full FlowRun.
   */
  async listFlowRuns({ conversationId, status, perPage = 25 } = {}) {
    const query = { per_page: perPage };
    if (conversationId !== undefined) query.conversation_id = conversationId;
    if (status !== undefined) query.status = status;
    const raw = await this._request('GET', 'v2/flows/runs', { query });
    raw.runs = (raw.runs ?? []).map((r) => new FlowRun(this, r));
    return raw;
  }

  /**
   * POST /flows/runs/{run}/resume — supply human input for the step
   * currently gating a paused run (status === "awaiting_human"). Throws
   * FireConflictError if the run isn't actually waiting, or `stepKey`
   * doesn't match the current gate.
   */
  async resumeFlowRun(runId, stepKey, humanInput, { verbosity = 'full' } = {}) {
    const query = verbosity !== 'full' ? { verbosity } : undefined;
    const body = { step_key: stepKey, human_input: humanInput };
    return new FlowRun(this, await this._request('POST', `v2/flows/runs/${runId}/resume`, { json: body, query }));
  }

  /**
   * Poll getFlowRun() until it lands on a terminal status — completed,
   * failed, cancelled, or awaiting_human (a human gate is a legitimate
   * stopping point, not a failure; check `result.status` and, if
   * "awaiting_human", call resumeFlowRun() with the gating step's
   * stepKey — or just use FlowRun#wait/#resume, which do this in place
   * on the run object itself).
   *
   * @throws {FireTimeoutError} if the run is still pending/running after `timeout` ms.
   */
  async waitForFlow(runId, { pollInterval = 1500, timeout = 120_000, verbosity = 'full' } = {}) {
    const deadline = Date.now() + timeout;
    for (;;) {
      const run = await this.getFlowRun(runId, { verbosity });
      if (run.isTerminal) return run;
      if (Date.now() >= deadline) {
        throw new FireTimeoutError(`flow run ${runId} still '${run.status}' after ${timeout}ms`);
      }
      await new Promise((resolve) => setTimeout(resolve, pollInterval));
    }
  }

  // ── v2 — self-service tier onboarding ───────────────────────────────

  /**
   * POST /billing/tier/redeem — trade a JP-issued code for a real token,
   * no existing token required. Returns `{token, response, client}` where
   * `client` is a ready FireClient already carrying the new token.
   *
   * @param {string} code
   * @param {string} name
   * @param {string} email
   * @param {{baseUrl?: string, timeout?: number, fetchImpl?: typeof fetch}} [opts]
   */
  static async redeemTierCode(code, name, email, { baseUrl = DEFAULT_BASE_URL, timeout = 60_000, fetchImpl = fetch } = {}) {
    const anon = new FireClient({ token: null, baseUrl, timeout, fetchImpl });
    const body = await anon._request('POST', 'v1/billing/tier/redeem', { json: { code, name, email } });
    const token = body.token;
    return { token, response: body, client: new FireClient({ token, baseUrl, timeout, fetchImpl }) };
  }
}
