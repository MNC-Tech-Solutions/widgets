const { getTenantToken, getApiKey } = require('/opt/nodejs/lib/secrets');
const { getCached, setCached, deleteCached, deleteAllCached, getTenant } = require('/opt/nodejs/lib/dynamo');
const ghl = require('/opt/nodejs/lib/ghl-client');
const { LambdaClient, InvokeCommand } = require('@aws-sdk/client-lambda');

const lambdaClient = new LambdaClient({});

const CORS = {
  'Content-Type': 'application/json',
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Expose-Headers': 'X-Cache, X-Partial',
};

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
      if (!resource) {
        // No resource specified — clear entire location cache (all pipelines, all resources)
        await deleteAllCached(`${locationId}#ghl`);
        return reply(200, { ok: true, cleared: 'all' });
      }
      const sk = pipelineId ? `${resource}#${pipelineId}` : resource;
      await deleteCached(`${locationId}#ghl`, sk);
      return reply(200, { ok: true, cleared: sk });
    }

    const token = await getTenantToken(locationId);

    // Route dispatch
    if (path.startsWith('/ghl/pipelines')) {
      return await cached(locationId, 'pipelines', () => ghl.fetchPipelines(token, locationId));
    }

    if (path.startsWith('/ghl/users')) {
      return await cached(locationId, 'users', () => ghl.fetchUsers(token, locationId));
    }

    if (path.startsWith('/ghl/opportunities')) {
      const { pipelineId } = qs;
      if (!pipelineId) return reply(400, { error: 'pipelineId required' });

      const pk = `${locationId}#ghl`;
      const sk = `opportunities#${pipelineId}`;

      const hit = await getCached(pk, sk);
      if (hit !== null) {
        return { statusCode: 200, headers: { ...CORS, 'X-Cache': 'HIT' }, body: JSON.stringify(hit) };
      }

      // Cache miss — 24s deadline to stay within API Gateway's 29s hard limit.
      // If we hit the deadline, we cache partial results and immediately trigger
      // the warmer async (fire-and-forget) to finish the job in the background.
      const deadline = Date.now() + 24000;
      const { opps, isPartial } = await ghl.fetchOpportunities(
        token, locationId, pipelineId, tenant.customFieldIds, deadline
      );
      await setCached(pk, sk, opps);

      if (isPartial) {
        const warmerFn = process.env.WARMER_FUNCTION_NAME || 'ghl-cache-warmer';
        lambdaClient.send(new InvokeCommand({
          FunctionName: warmerFn,
          InvocationType: 'Event', // async fire-and-forget
          Payload: JSON.stringify({ locationId, pipelineId }),
        })).catch(e => console.error('Failed to invoke warmer:', e.message));
      }

      const headers = { ...CORS, 'X-Cache': 'MISS', ...(isPartial && { 'X-Partial': 'true' }) };
      return { statusCode: 200, headers, body: JSON.stringify(opps) };
    }

    const messagesMatch = path.match(/\/ghl\/conversations\/([^/]+)\/messages$/);
    if (messagesMatch) {
      const conversationId = messagesMatch[1];
      return await cached(locationId, `conversation-messages#${conversationId}`, () =>
        ghl.fetchConversationMessages(token, conversationId));
    }

    if (path === '/ghl/conversations') {
      const extra = { ...qs };
      delete extra.locationId;
      // Paginated requests (startAfterDate present) must bypass cache —
      // each page has a different cursor so they can't share a cache entry.
      if (extra.startAfterDate) {
        const conversations = await ghl.fetchConversations(token, locationId, extra);
        return reply(200, { conversations });
      }
      return await cached(locationId, 'conversations', async () => ({
        conversations: await ghl.fetchConversations(token, locationId, extra),
      }));
    }

    if (method === 'POST' && path.startsWith('/ghl/contacts/search')) {
      const pk = `${locationId}#ghl`;
      const sk = 'contacts';
      const hit = await getCached(pk, sk);
      // Only trust the cache once it holds a complete contact list — a partial
      // result (deadline hit mid-pagination) must never be served as a HIT, or
      // callers get stuck on the first ~100 contacts forever.
      if (hit?.complete === true && Array.isArray(hit.contacts)) {
        return { statusCode: 200, headers: { ...CORS, 'X-Cache': 'HIT' }, body: JSON.stringify(hit) };
      }
      const deadline = Date.now() + 24000;
      const { contacts, isPartial } = await ghl.fetchAllContacts(token, locationId, deadline);
      if (!isPartial) {
        await setCached(pk, sk, { contacts, complete: true });
      }
      if (isPartial) {
        const warmerFn = process.env.WARMER_FUNCTION_NAME || 'ghl-cache-warmer';
        lambdaClient.send(new InvokeCommand({
          FunctionName: warmerFn,
          InvocationType: 'Event',
          Payload: JSON.stringify({ locationId, resource: 'contacts' }),
        })).catch(e => console.error('Failed to invoke warmer for contacts:', e.message));
      }
      const headers = { ...CORS, 'X-Cache': 'MISS', ...(isPartial && { 'X-Partial': 'true' }) };
      return { statusCode: 200, headers, body: JSON.stringify({ contacts }) };
    }

    const notesMatch = path.match(/\/ghl\/contacts\/([^/]+)\/notes/);
    if (notesMatch) {
      const contactId = notesMatch[1];
      return await cached(locationId, `notes#${contactId}`, () =>
        ghl.fetchContactNotes(token, contactId));
    }

    if (path.startsWith('/ghl/calendars/events')) {
      const { startTime, endTime, userIds } = qs;
      const cacheKey = `calendar#${startTime}#${endTime}`;
      return await cached(locationId, cacheKey, async () => {
        const ids = userIds ? userIds.split(',').filter(Boolean) : [];
        const batches = await Promise.all(
          ids.map(uid => ghl.fetchCalendarEvents(token, locationId, startTime, endTime, uid).catch(() => []))
        );
        return batches.flat();
      });
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
