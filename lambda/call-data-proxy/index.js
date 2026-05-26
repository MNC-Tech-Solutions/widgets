const { getCallCredentials, getApiKey } = require('/opt/nodejs/lib/secrets');
const { getCached, setCached, getTenant } = require('/opt/nodejs/lib/dynamo');

const CORS = { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' };
const AVANSER_API = 'https://api.avanser.com/JSON';

exports.handler = async (event) => {
  try {
    const apiKey = await getApiKey();
    const requestKey = (event.headers || {})['x-api-key'] || (event.headers || {})['X-Api-Key'];
    if (requestKey !== apiKey) {
      return reply(401, { error: 'Unauthorized' });
    }

    const qs = event.queryStringParameters || {};
    const { locationId, clientId, dateFrom, limit = '10000' } = qs;

    if (!locationId) return reply(400, { error: 'locationId required' });
    if (!clientId) return reply(400, { error: 'clientId required' });
    if (!dateFrom) return reply(400, { error: 'dateFrom required' });

    const tenant = await getTenant(locationId);
    if (!tenant) return reply(403, { error: 'Unknown location' });

    // Verify the requested clientId belongs to this location
    const validClientIds = (tenant.callClientIds || []).map(a => String(a.clientid));
    if (!validClientIds.includes(String(clientId))) {
      return reply(403, { error: 'clientId not authorised for this location' });
    }

    const pk = `${locationId}#call`;
    const sk = `cdr#${clientId}#${dateFrom}`;

    const hit = await getCached(pk, sk);
    if (hit !== null) {
      return { statusCode: 200, headers: { ...CORS, 'X-Cache': 'HIT' }, body: JSON.stringify(hit) };
    }

    const { username, password } = await getCallCredentials();
    const creds = Buffer.from(`${username}:${password}`).toString('base64');

    const url = `${AVANSER_API}?action=getCDR&client_id=${clientId}&date_from=${encodeURIComponent(dateFrom)}&limit=${limit}`;
    const res = await fetch(url, {
      headers: {
        Authorization: `Basic ${creds}`,
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
      },
    });
    if (!res.ok) throw new Error(`Call CDR proxy returned ${res.status}`);

    const data = await res.json();
    await setCached(pk, sk, data);

    return { statusCode: 200, headers: { ...CORS, 'X-Cache': 'MISS' }, body: JSON.stringify(data) };
  } catch (err) {
    console.error('call-data-proxy error:', err);
    return reply(500, { error: err.message });
  }
};

function reply(statusCode, body) {
  return { statusCode, headers: CORS, body: JSON.stringify(body) };
}
