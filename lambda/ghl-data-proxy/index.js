const { getTenantToken, getApiKey } = require('/opt/nodejs/lib/secrets');
const { getCached, setCached, deleteCached, getTenant } = require('/opt/nodejs/lib/dynamo');
const ghl = require('/opt/nodejs/lib/ghl-client');

const CORS = { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' };

exports.handler = async (event) => {
  try {
    // Auth check
    const apiKey = await getApiKey();
    const requestKey = (event.headers || {})['x-api-key'] || (event.headers || {})['X-Api-Key'];
    if (requestKey !== apiKey) {
      return reply(401, { error: 'Unauthorized' });
    }

    const qs = event.queryStringParameters || {};
    const { locationId } = qs;
    if (!locationId) return reply(400, { error: 'locationId required' });

    const tenant = await getTenant(locationId);
    if (!tenant) return reply(403, { error: 'Unknown location' });

    const method = (event.requestContext?.http?.method || event.httpMethod || 'GET').toUpperCase();
    const path = event.requestContext?.http?.path || event.path || '';

    // Cache invalidation
    if (method === 'DELETE' && path.includes('/ghl/cache')) {
      const { resource, pipelineId } = qs;
      if (!resource) return reply(400, { error: 'resource required' });
      const sk = pipelineId ? `${resource}#${pipelineId}` : resource;
      await deleteCached(`${locationId}#ghl`, sk);
      return reply(200, { ok: true });
    }

    const token = await getTenantToken(locationId);

    // Route dispatch
    if (path.startsWith('/ghl/pipelines')) {
      return cached(locationId, 'pipelines', () => ghl.fetchPipelines(token, locationId));
    }

    if (path.startsWith('/ghl/users')) {
      return cached(locationId, 'users', () => ghl.fetchUsers(token, locationId));
    }

    if (path.startsWith('/ghl/opportunities')) {
      const { pipelineId } = qs;
      if (!pipelineId) return reply(400, { error: 'pipelineId required' });
      return cached(locationId, `opportunities#${pipelineId}`, () =>
        ghl.fetchOpportunities(token, locationId, pipelineId, tenant.customFieldIds));
    }

    if (path.startsWith('/ghl/conversations')) {
      const extra = { ...qs };
      delete extra.locationId;
      return cached(locationId, 'conversations', () =>
        ghl.fetchConversations(token, locationId, extra));
    }

    if (method === 'POST' && path.startsWith('/ghl/contacts/search')) {
      const body = event.body ? JSON.parse(event.body) : {};
      // Contacts search varies by body; use a short stable key based on body content
      const bodyKey = Buffer.from(JSON.stringify(body)).toString('base64').slice(0, 40);
      return cached(locationId, `contacts#${bodyKey}`, () =>
        ghl.searchContacts(token, locationId, body));
    }

    const notesMatch = path.match(/\/ghl\/contacts\/([^/]+)\/notes/);
    if (notesMatch) {
      const contactId = notesMatch[1];
      return cached(locationId, `notes#${contactId}`, () =>
        ghl.fetchContactNotes(token, contactId));
    }

    if (path.startsWith('/ghl/calendars/events')) {
      const { startTime, endTime, userId } = qs;
      const cacheKey = `calendar#${startTime}#${endTime}${userId ? `#${userId}` : ''}`;
      return cached(locationId, cacheKey, () =>
        ghl.fetchCalendarEvents(token, locationId, startTime, endTime, userId));
    }

    return reply(404, { error: 'Route not found' });
  } catch (err) {
    console.error('ghl-data-proxy error:', err);
    return reply(500, { error: err.message });
  }
};

async function cached(locationId, sk, fetcher) {
  const pk = `${locationId}#ghl`;
  const hit = await getCached(pk, sk);
  if (hit !== null) {
    return { statusCode: 200, headers: { ...CORS, 'X-Cache': 'HIT' }, body: JSON.stringify(hit) };
  }
  const data = await fetcher();
  await setCached(pk, sk, data);
  return { statusCode: 200, headers: { ...CORS, 'X-Cache': 'MISS' }, body: JSON.stringify(data) };
}

function reply(statusCode, body) {
  return { statusCode, headers: CORS, body: JSON.stringify(body) };
}
