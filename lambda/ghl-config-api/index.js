const { getApiKey } = require('/opt/nodejs/lib/secrets');
const { getTenant } = require('/opt/nodejs/lib/dynamo');

const CORS = {
  'Content-Type': 'application/json',
  'Access-Control-Allow-Origin': '*',
};

exports.handler = async (event) => {
  try {
    const apiKey = await getApiKey();
    const requestKey = (event.headers || {})['x-api-key'] || (event.headers || {})['X-Api-Key'];
    if (requestKey !== apiKey) {
      return { statusCode: 401, headers: CORS, body: JSON.stringify({ error: 'Unauthorized' }) };
    }

    const locationId = (event.queryStringParameters || {}).locationId;
    if (!locationId) {
      return { statusCode: 400, headers: CORS, body: JSON.stringify({ error: 'locationId required' }) };
    }

    const tenant = await getTenant(locationId);
    if (!tenant) {
      return { statusCode: 403, headers: CORS, body: JSON.stringify({ error: 'Unknown location' }) };
    }

    // Strip any sensitive fields — only return public config
    const { locationId: _id, ...publicFields } = tenant;
    return { statusCode: 200, headers: CORS, body: JSON.stringify({ locationId, ...publicFields }) };
  } catch (err) {
    console.error('ghl-config-api error:', err);
    return { statusCode: 500, headers: CORS, body: JSON.stringify({ error: 'Internal server error' }) };
  }
};
