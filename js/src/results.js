/**
 * Typed result objects returned by FireClient.
 *
 * Three separate root families, matching the three layers Fire itself
 * enforces server-side (FirePipeline / WorkflowContract / FlowEngine):
 *
 * - L1 one-shot calls (ChatResult, ParallelChatResult, ImageResult) —
 *   plain classes, no behavior beyond construction from a raw response.
 * - L2 workflows (WorkflowResult) — deliberately thin. Each workflow slug
 *   (image, article, ...) defines its own response contract server-side;
 *   this does not attempt to model every field of every workflow. Use
 *   `.raw` for anything workflow-specific.
 * - v2 flows (FlowRun, FlowStep) — the one type that owns behavior,
 *   because a flow run is inherently not a one-shot result: `.wait()`
 *   polls, `.resume()` mutates server-side state.
 *
 * Every object keeps the full original response as `.raw` — a field this
 * class doesn't model yet (or never will, for something workflow- or
 * provider-specific) is always still reachable, so a server-side addition
 * never requires an SDK release just to stay usable.
 */

const FLOW_TERMINAL_STATUSES = new Set(['completed', 'failed', 'cancelled', 'awaiting_human']);

// ─── L1 ────────────────────────────────────────────────────────────────

export class Usage {
  constructor(input, output) {
    this.input = input ?? null;
    this.output = output ?? null;
  }
}

/** POST /v1/chat — one chat completion. */
export class ChatResult {
  constructor({ content, model, provider, usage, priceUsd, logId, toolCalls, raw }) {
    this.content = content;
    this.model = model;
    this.provider = provider;
    this.usage = usage;
    this.priceUsd = priceUsd;
    this.logId = logId;
    this.toolCalls = toolCalls;
    this.raw = raw;
  }

  static fromRaw(raw) {
    const usage = raw.usage ?? {};
    const meta = raw.meta ?? {};
    return new ChatResult({
      content: raw.content ?? '',
      model: raw.model ?? '',
      provider: raw.provider ?? '',
      usage: new Usage(usage.input, usage.output),
      priceUsd: meta.price?.usd ?? null,
      logId: meta.log_id ?? raw.log_id ?? null,
      toolCalls: raw.tool_calls ?? null,
      raw,
    });
  }
}

/** One item inside a ParallelChatResult — can fail independently of its siblings. */
export class ChatItemResult {
  constructor({ ok, content, model, provider, usage, priceUsd, error, raw }) {
    this.ok = ok;
    this.content = content;
    this.model = model;
    this.provider = provider;
    this.usage = usage;
    this.priceUsd = priceUsd;
    this.error = error;
    this.raw = raw;
  }

  static fromRaw(raw) {
    const usageRaw = raw.usage;
    const meta = raw.meta ?? {};
    return new ChatItemResult({
      ok: raw.ok ?? true,
      content: raw.content ?? null,
      model: raw.model ?? null,
      provider: raw.provider ?? null,
      usage: usageRaw ? new Usage(usageRaw.input, usageRaw.output) : null,
      priceUsd: meta.price?.usd ?? null,
      error: raw.error ?? null,
      raw,
    });
  }
}

/** POST /v1/chat/parallel — N independent completions, input order preserved. */
export class ParallelChatResult {
  constructor(results, raw) {
    this.results = results;
    this.raw = raw;
  }

  static fromRaw(raw) {
    return new ParallelChatResult((raw.results ?? []).map(ChatItemResult.fromRaw), raw);
  }
}

export class ImageFile {
  constructor(b64, url) {
    this.b64 = b64 ?? null;
    this.url = url ?? null;
  }
}

/** POST /v1/image. */
export class ImageResult {
  constructor({ images, model, provider, priceUsd, logId, raw }) {
    this.images = images;
    this.model = model;
    this.provider = provider;
    this.priceUsd = priceUsd;
    this.logId = logId;
    this.raw = raw;
  }

  static fromRaw(raw) {
    const meta = raw.meta ?? {};
    return new ImageResult({
      images: (raw.images ?? []).map((i) => new ImageFile(i.b64, i.url)),
      model: raw.model ?? '',
      provider: raw.provider ?? '',
      priceUsd: meta.price?.usd ?? null,
      logId: meta.log_id ?? null,
      raw,
    });
  }
}

// ─── L2 ────────────────────────────────────────────────────────────────

/** POST /v1/workflows/{slug}. Deliberately thin — see module docstring. */
export class WorkflowResult {
  constructor(workflow, raw) {
    this.workflow = workflow;
    this.raw = raw;
  }

  static fromRaw(workflow, raw) {
    return new WorkflowResult(workflow, raw);
  }
}

// ─── v2 ────────────────────────────────────────────────────────────────

export class FlowStep {
  constructor({ stepKey, kind, status, dependsOn, error, output, context, promptForHuman, humanInput, raw }) {
    this.stepKey = stepKey;
    this.kind = kind;
    this.status = status;
    this.dependsOn = dependsOn;
    this.error = error;
    this.output = output ?? null;
    this.context = context ?? null;
    this.promptForHuman = promptForHuman ?? null;
    this.humanInput = humanInput ?? null;
    this.raw = raw;
  }

  static fromRaw(raw) {
    return new FlowStep({
      stepKey: raw.step_key,
      kind: raw.kind,
      status: raw.status,
      dependsOn: raw.depends_on ?? [],
      error: raw.error ?? null,
      output: raw.output,
      context: raw.context,
      promptForHuman: raw.prompt_for_human,
      humanInput: raw.human_input,
      raw,
    });
  }
}

/**
 * v2 — a stateful, pollable flow run.
 *
 * Unlike every other result type in this module, FlowRun is not a frozen
 * value — it holds a reference back to the client so `.wait()` and
 * `.resume()` read naturally as verbs on the run itself, e.g.:
 *
 *     const run = await client.runFlow({ flowSlug: 'triad', input: {...} });
 *     await run.wait();
 *     if (run.status === 'awaiting_human') {
 *       await run.resume(run.gatingStep().stepKey, { note: '...' });
 *       await run.wait();
 *     }
 *     console.log(run.output.content);
 */
export class FlowRun {
  constructor(client, raw) {
    this._client = client;
    this._apply(raw);
  }

  _apply(raw) {
    this.raw = raw;
    this.runId = raw.run_id;
    this.status = raw.status;
    this.conversationId = raw.conversation_id ?? null;
    this.input = raw.input ?? null;
    this.output = raw.output ?? null;
    this.error = raw.error ?? null;
    this.totalCostUsd = raw.total_cost_usd ?? null;
    this.startedAt = raw.started_at ?? null;
    this.completedAt = raw.completed_at ?? null;
    this.steps = (raw.steps ?? []).map(FlowStep.fromRaw);
  }

  get isTerminal() {
    return FLOW_TERMINAL_STATUSES.has(this.status);
  }

  /** The human_gate step currently pausing this run, if status === 'awaiting_human'. */
  gatingStep() {
    return this.steps.find((s) => s.status === 'awaiting_human') ?? null;
  }

  async refresh({ verbosity = 'full' } = {}) {
    const result = await this._client.getFlowRun(this.runId, { verbosity });
    this._apply(result.raw);
    return this;
  }

  async wait({ pollInterval = 1500, timeout = 120_000, verbosity = 'full' } = {}) {
    const result = await this._client.waitForFlow(this.runId, { pollInterval, timeout, verbosity });
    this._apply(result.raw);
    return this;
  }

  async resume(stepKey, humanInput, { verbosity = 'full' } = {}) {
    const result = await this._client.resumeFlowRun(this.runId, stepKey, humanInput, { verbosity });
    this._apply(result.raw);
    return this;
  }
}
