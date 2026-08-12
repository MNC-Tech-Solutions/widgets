require('dotenv').config();

function int(name, fallback) {
  const v = process.env[name];
  if (v === undefined || v === '') return fallback;
  const n = Number(v);
  return Number.isFinite(n) ? n : fallback;
}

module.exports = {
  port: int('PORT', 3000),

  webhookTargetUrl: process.env.WEBHOOK_TARGET_URL || '',

  rotateSizeBytes: int('ROTATE_SIZE_BYTES', 500 * 1024 * 1024), // 500MB default
  diskFreeFloorBytes: int('DISK_FREE_FLOOR_BYTES', 2 * 1024 * 1024 * 1024), // 2GB default

  maxRetries: int('MAX_RETRIES', 3),
  retryBaseDelayMs: int('RETRY_BASE_DELAY_MS', 1000),
  forwardTimeoutMs: int('FORWARD_TIMEOUT_MS', 10000),

  smtp: {
    host: process.env.SMTP_HOST || '',
    port: int('SMTP_PORT', 587),
    secure: process.env.SMTP_SECURE === 'true',
    user: process.env.SMTP_USER || '',
    pass: process.env.SMTP_PASS || '',
  },
  alertEmailTo: process.env.ALERT_EMAIL_TO || '',
  alertEmailFrom: process.env.ALERT_EMAIL_FROM || process.env.SMTP_USER || '',
};
