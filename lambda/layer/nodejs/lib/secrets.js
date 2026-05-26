const { SSMClient, GetParameterCommand } = require('@aws-sdk/client-ssm');

const ssm = new SSMClient({});
const cache = new Map();
const CACHE_TTL = 5 * 60 * 1000;

async function getParameter(name) {
  const hit = cache.get(name);
  if (hit && Date.now() - hit.ts < CACHE_TTL) return hit.value;

  if (process.env.LOCAL_DEV === 'true') {
    const envKey = name.replace(/\//g, '_').replace(/^_/, '').toUpperCase();
    const value = process.env[envKey];
    if (value) { cache.set(name, { value, ts: Date.now() }); return value; }
    throw new Error(`Local dev: env var not set for param ${name} (expected ${envKey})`);
  }

  const result = await ssm.send(new GetParameterCommand({ Name: name, WithDecryption: true }));
  const value = result.Parameter.Value;
  cache.set(name, { value, ts: Date.now() });
  return value;
}

function getTenantToken(locationId) {
  return getParameter(`/ghl-widgets/tenants/${locationId}/access-token`);
}

async function getCallCredentials() {
  const [username, password] = await Promise.all([
    getParameter('/ghl-widgets/call/username'),
    getParameter('/ghl-widgets/call/password'),
  ]);
  return { username, password };
}

function getApiKey() {
  return getParameter('/ghl-widgets/api-key');
}

module.exports = { getTenantToken, getCallCredentials, getApiKey };
