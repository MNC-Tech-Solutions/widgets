const GHL_BASE = 'https://services.leadconnectorhq.com';
const GHL_VERSION = '2021-07-28';

async function ghlFetch(token, path, options = {}) {
  const url = `${GHL_BASE}${path}`;
  const res = await fetchWithRetry(url, {
    ...options,
    headers: {
      Authorization: `Bearer ${token}`,
      Version: GHL_VERSION,
      'Content-Type': 'application/json',
      ...options.headers,
    },
  });
  if (!res.ok) {
    const body = await res.text().catch(() => '');
    throw new Error(`GHL ${res.status} at ${path}: ${body}`);
  }
  return res.json();
}

async function fetchWithRetry(url, options, attempt = 0) {
  const res = await fetch(url, options);
  if (res.status === 429 && attempt < 3) {
    const delay = [10000, 30000, 60000][attempt];
    console.warn(`GHL 429 at ${url} — retrying in ${delay / 1000}s (attempt ${attempt + 1})`);
    await new Promise(r => setTimeout(r, delay));
    return fetchWithRetry(url, options, attempt + 1);
  }
  return res;
}

async function fetchPipelines(token, locationId) {
  const data = await ghlFetch(token, `/opportunities/pipelines?locationId=${locationId}`);
  return data.pipelines || [];
}

async function fetchUsers(token, locationId) {
  const data = await ghlFetch(token, `/users/?locationId=${locationId}`);
  return (data.users || []).map(u => ({
    id: u.id,
    name: [u.firstName, u.lastName].filter(Boolean).join(' ') || u.email,
    email: u.email,
  }));
}

async function fetchOpportunities(token, locationId, pipelineId, customFieldIds = {}, deadlineMs = null) {
  const results = [];
  let startAfter = null;
  let startAfterId = null;
  let page = 0;
  let isPartial = false;

  while (page < 150) {
    if (deadlineMs && Date.now() > deadlineMs) {
      console.warn(`fetchOpportunities: deadline reached after ${page} pages — returning ${results.length} partial results`);
      isPartial = true;
      break;
    }

    const params = new URLSearchParams({ location_id: locationId, pipeline_id: pipelineId, limit: '100' });
    if (startAfter) params.set('startAfter', startAfter);
    if (startAfterId) params.set('startAfterId', startAfterId);

    const data = await ghlFetch(token, `/opportunities/search?${params}`);
    const opps = data.opportunities || [];
    for (const opp of opps) results.push(processOpportunity(opp, pipelineId, customFieldIds));

    const meta = data.meta || {};
    if (!meta.startAfter) break;
    startAfter = meta.startAfter;
    startAfterId = meta.startAfterId;
    page++;
  }

  return { opps: results, isPartial };
}

function processOpportunity(opp, pipelineId, customFieldIds) {
  const customFields = opp.customFields || [];
  const getField = (fieldId) => {
    if (!fieldId) return null;
    const f = customFields.find(c => c.id === fieldId);
    if (!f) return null;
    // fieldValueString is the human-readable label (important for select/dropdown fields)
    return f.fieldValueString || f.fieldValue || null;
  };

  return {
    id: opp.id,
    pipelineId,
    pipelineStageId: opp.pipelineStageId,
    name: opp.name,
    assignedTo: opp.assignedTo,
    status: opp.status,
    source: opp.source,
    createdAt: opp.createdAt,
    sourceCategory: getField(customFieldIds.sourceCategory),
    project: getField(customFieldIds.project),
    team: getField(customFieldIds.team),
    contact: { name: opp.contact?.name, phone: opp.contact?.phone },
    monetaryValue: opp.monetaryValue,
  };
}

async function fetchConversations(token, locationId, extraParams = {}) {
  const results = [];
  const qs = new URLSearchParams({ locationId, limit: '100', ...extraParams });
  let page = 0;

  while (page < 100) {
    const data = await ghlFetch(token, `/conversations/search?${qs}`);
    const convos = data.conversations || [];
    results.push(...convos);
    if (!data.meta?.nextPageUrl) break;
    if (data.meta.lastMessageDate) qs.set('lastMessageDate', data.meta.lastMessageDate);
    page++;
  }

  return results;
}

async function searchContacts(token, locationId, body) {
  return ghlFetch(token, `/contacts/search`, {
    method: 'POST',
    body: JSON.stringify({ locationId, ...body }),
  });
}

async function fetchContactNotes(token, contactId) {
  const data = await ghlFetch(token, `/contacts/${contactId}/notes`);
  return data.notes || [];
}

async function fetchCalendarEvents(token, locationId, startTime, endTime, userId) {
  const qs = new URLSearchParams({ locationId, startTime, endTime });
  if (userId) qs.set('userId', userId);
  const data = await ghlFetch(token, `/calendars/events?${qs}`);
  return data.events || [];
}

module.exports = {
  fetchPipelines,
  fetchUsers,
  fetchOpportunities,
  fetchConversations,
  searchContacts,
  fetchContactNotes,
  fetchCalendarEvents,
};
