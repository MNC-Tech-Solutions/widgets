const config = require('../config');

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function postOnce(body, headers) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), config.forwardTimeoutMs);
  try {
    const res = await fetch(config.webhookTargetUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', ...headers },
      body,
      signal: controller.signal,
    });
    const text = await res.text().catch(() => '');
    return { ok: res.ok, status: res.status, body: text.slice(0, 2000) };
  } finally {
    clearTimeout(timer);
  }
}

// Forwards raw body bytes to the leadconnectorhq webhook, retrying with
// exponential backoff. Resolves with the outcome (never throws) so callers
// can log it without wrapping every call in try/catch.
async function forwardWithRetry(rawBody, headers = {}) {
  let lastError = null;
  let lastResult = null;

  for (let attempt = 1; attempt <= config.maxRetries; attempt += 1) {
    try {
      const result = await postOnce(rawBody, headers);
      lastResult = result;
      if (result.ok) {
        return { success: true, attempts: attempt, status: result.status, body: result.body };
      }
      lastError = new Error(`non-2xx response: ${result.status}`);
    } catch (err) {
      lastError = err;
    }

    if (attempt < config.maxRetries) {
      const delay = config.retryBaseDelayMs * 2 ** (attempt - 1);
      await sleep(delay);
    }
  }

  return {
    success: false,
    attempts: config.maxRetries,
    status: lastResult ? lastResult.status : null,
    error: lastError ? lastError.message : 'unknown error',
  };
}

module.exports = { forwardWithRetry };
