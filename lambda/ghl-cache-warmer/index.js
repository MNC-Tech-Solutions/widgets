const { getTenantToken } = require('/opt/nodejs/lib/secrets');
const { getAllTenants, getTenant, getCached, setCached } = require('/opt/nodejs/lib/dynamo');
const ghl = require('/opt/nodejs/lib/ghl-client');

exports.handler = async (event = {}) => {
  // Targeted invocation: warm one specific pipeline
  if (event.locationId && event.pipelineId) {
    await warmSinglePipeline(event.locationId, event.pipelineId);
    return;
  }

  // Targeted invocation: warm contacts for one location
  if (event.locationId && event.resource === 'contacts') {
    await warmContacts(event.locationId);
    return;
  }

  // Scheduled full-cycle warm
  const tenants = await getAllTenants();
  console.log(`Cache warming started — ${tenants.length} tenants`);

  // Process in groups of 5 to avoid bursting GHL rate limits across all tenants simultaneously
  for (let i = 0; i < tenants.length; i += 5) {
    const group = tenants.slice(i, i + 5);
    await Promise.all(group.map(warmTenant));
    if (i + 5 < tenants.length) await sleep(1000);
  }

  console.log('Cache warming complete');
};

async function warmSinglePipeline(locationId, pipelineId) {
  try {
    const tenant = await getTenant(locationId);
    if (!tenant) { console.error(`warmSinglePipeline: unknown location ${locationId}`); return; }
    const token = await getTenantToken(locationId);
    const pk = `${locationId}#ghl`;
    const sk = `opportunities#${pipelineId}`;
    // No deadline — Lambda has 900s, fetch everything
    const { opps } = await ghl.fetchOpportunities(token, locationId, pipelineId, tenant.customFieldIds || {});
    await setCached(pk, sk, opps);
    console.log(`warmSinglePipeline: ${locationId}/${pipelineId} — ${opps.length} opps`);
  } catch (err) {
    console.error(`warmSinglePipeline failed ${locationId}/${pipelineId}: ${err.message}`);
  }
}

async function warmContacts(locationId) {
  try {
    const token = await getTenantToken(locationId);
    const { contacts } = await ghl.fetchAllContacts(token, locationId); // no deadline
    await setCached(`${locationId}#ghl`, 'contacts', { contacts });
    console.log(`warmContacts: ${locationId} — ${contacts.length} contacts`);
  } catch (err) {
    console.error(`warmContacts failed ${locationId}: ${err.message}`);
  }
}

async function warmTenant(tenant) {
  const { locationId, name, customFieldIds = {} } = tenant;

  try {
    const token = await getTenantToken(locationId);
    const pk = `${locationId}#ghl`;

    // Pipelines — 24h TTL, skip if still fresh
    let pipelines = await getCached(pk, 'pipelines');
    if (!pipelines) {
      pipelines = await ghl.fetchPipelines(token, locationId);
      await setCached(pk, 'pipelines', pipelines);
    }

    // Users — 6h TTL, skip if still fresh
    const usersCached = await getCached(pk, 'users');
    if (!usersCached) {
      const users = await ghl.fetchUsers(token, locationId);
      await setCached(pk, 'users', users);
    }

    // Opportunities — 20min TTL, always refresh (warmer runs every 20min).
    await Promise.all(pipelines.map(async (pipeline) => {
      const sk = `opportunities#${pipeline.id}`;
      const deadline = Date.now() + 90000;
      try {
        const { opps } = await ghl.fetchOpportunities(token, locationId, pipeline.id, customFieldIds, deadline);
        await setCached(pk, sk, opps);
      } catch (pipelineErr) {
        console.error(`Failed to warm pipeline ${pipeline.id} for ${name}: ${pipelineErr.message}`);
      }
    }));

    // Contacts — 20min TTL, always refresh
    const contactsCached = await getCached(pk, 'contacts');
    if (!contactsCached) {
      await warmContacts(locationId);
    }

    console.log(`Warmed: ${name} (${locationId}) — ${pipelines.length} pipeline(s)`);
  } catch (err) {
    // Log but don't throw — one failed tenant should not abort the rest
    console.error(`Failed to warm ${name} (${locationId}): ${err.message}`);
  }
}

function sleep(ms) {
  return new Promise(r => setTimeout(r, ms));
}
