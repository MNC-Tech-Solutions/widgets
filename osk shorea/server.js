const express = require('express');
const crypto = require('crypto');
const config = require('./config');
const logger = require('./lib/logger');
const forwarder = require('./lib/forwarder');
const mailer = require('./lib/mailer');

const app = express();
app.use(express.raw({ type: () => true, limit: '25mb' }));

const REDACTED_HEADERS = new Set(['authorization', 'cookie']);

function safeHeaders(headers) {
  const out = {};
  for (const [key, value] of Object.entries(headers)) {
    out[key] = REDACTED_HEADERS.has(key.toLowerCase()) ? '[redacted]' : value;
  }
  return out;
}

app.post('/webhook', async (req, res) => {
  const id = crypto.randomUUID();
  const receivedAt = new Date().toISOString();
  const rawBuf = Buffer.isBuffer(req.body) ? req.body : Buffer.from('');
  const rawText = rawBuf.toString('utf8');

  let bodyForLog;
  try {
    bodyForLog = JSON.parse(rawText);
  } catch {
    bodyForLog = rawText;
  }

  try {
    await logger.write({
      id,
      type: 'received',
      receivedAt,
      ip: req.ip,
      headers: safeHeaders(req.headers),
      body: bodyForLog,
    });
  } catch (err) {
    console.error('[server] failed to log request', id, err);
    res.status(503).json({ received: false, error: 'log write failed, try again shortly' });
    return;
  }

  // Ack receipt immediately — the caller does not wait on the forward result.
  res.status(200).json({ received: true, id });

  forwarder
    .forwardWithRetry(rawBuf, { 'Content-Type': req.headers['content-type'] || 'application/json' })
    .then((result) => {
      logger
        .write({ id, type: 'forward_result', at: new Date().toISOString(), ...result })
        .catch((err) => console.error('[server] failed to log forward result', id, err));

      if (!result.success) {
        mailer
          .sendAlert(
            `osk: forward failed for request ${id}`,
            `Request ${id} received at ${receivedAt} failed to forward to ${config.webhookTargetUrl} ` +
              `after ${result.attempts} attempts.\nLast status: ${result.status}\nError: ${result.error}`
          )
          .catch((err) => console.error('[mailer] forward-failure alert failed', err));
      }
    })
    .catch((err) => console.error('[server] unexpected forwarder error', id, err));
});

app.get('/health', (req, res) => {
  res.json({ status: 'ok', time: new Date().toISOString() });
});

async function start() {
  if (!config.webhookTargetUrl) {
    console.warn('[server] WEBHOOK_TARGET_URL is not set — forwarding will fail');
  }
  await logger.init();

  const server = app.listen(config.port, () => {
    console.log(`[server] listening on port ${config.port}`);
  });

  const shutdown = async () => {
    console.log('[server] shutting down...');
    server.close();
    await logger.shutdown();
    process.exit(0);
  };
  process.on('SIGTERM', shutdown);
  process.on('SIGINT', shutdown);
}

start().catch((err) => {
  console.error('[server] failed to start', err);
  process.exit(1);
});
