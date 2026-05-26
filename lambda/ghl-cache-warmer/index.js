const { getTenantToken } = require('/opt/nodejs/lib/secrets');
const { getAllTenants, getCached, setCached } = require('/opt/nodejs/lib/dynamo');
const ghl = require('/opt/nodejs/lib/ghl-client');

exports.handler = async () => {
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
    // Fetch all pipelines in parallel; allow up to 90s per pipeline so large
    // tenants don't time out. Errors on one pipeline don't block the others.
    await Promise.all(pipelines.map(async (pipeline) => {
      const sk = `opportunities#${pipeline.id}`;
      const deadline = Date.now() + 90000; // 90s per pipeline
      try {
        const opps = await ghl.fetchOpportunities(token, locationId, pipeline.id, customFieldIds, deadline);
        await setCached(pk, sk, opps);
      } catch (pipelineErr) {
        console.error(`Failed to warm pipeline ${pipeline.id} for ${name}: ${pipelineErr.message}`);
      }
    }));

    console.log(`Warmed: ${name} (${locationId}) — ${pipelines.length} pipeline(s)`);
  } catch (err) {
    // Log but don't throw — one failed tenant should not abort the rest
    console.error(`Failed to warm ${name} (${locationId}): ${err.message}`);
  }
}

function sleep(ms) {
  return new Promise(r => setTimeout(r, ms));
}
