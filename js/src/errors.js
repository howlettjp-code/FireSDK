/**
 * Errors raised by FireClient. Every FireError carries the parsed response
 * body (`.response`), the HTTP status (`.statusCode`), and Fire's own
 * `error_code` (`.errorCode`) when present, so a caller can branch on
 * structured data instead of parsing a message string. Mirrors the
 * Python/PHP SDKs' exception hierarchy exactly.
 */
export class FireError extends Error {
  /**
   * @param {string} message
   * @param {{statusCode?: number|null, errorCode?: string|null, response?: object}} [opts]
   */
  constructor(message, { statusCode = null, errorCode = null, response = {} } = {}) {
    super(message);
    this.name = this.constructor.name;
    this.statusCode = statusCode;
    this.errorCode = errorCode;
    this.response = response;
  }
}

/** 401 — missing, malformed, revoked, or under-scoped token. */
export class FireAuthError extends FireError {}

/** 402/500 — no billing tier resolvable, or account has no balance left. */
export class FireBillingError extends FireError {}

/**
 * 422 — request failed validation. `.response` includes field errors
 * (Laravel-style `errors`) or, for flow specs, a `details` list from
 * FlowValidator.
 */
export class FireValidationError extends FireError {}

/**
 * 409 — e.g. resuming a flow run that isn't awaiting human input, or with
 * a step_key that doesn't match the step currently gating it.
 */
export class FireConflictError extends FireError {}

/** 404 — unknown flow/agent-config slug, run id, or model. */
export class FireNotFoundError extends FireError {}

/** 5xx not covered above — provider/pipeline failure, etc. */
export class FireServerError extends FireError {}

/**
 * Raised by FireClient#waitForFlow when a run is still pending/running
 * after the given timeout — a client-side polling timeout, not a Fire API
 * error, so it deliberately does not extend FireError.
 */
export class FireTimeoutError extends Error {
  constructor(message) {
    super(message);
    this.name = 'FireTimeoutError';
  }
}
