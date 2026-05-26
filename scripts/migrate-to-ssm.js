#!/usr/bin/env node
/**
 * Phase 1 migration script — run ONCE to copy credentials from JSON config files
 * into AWS SSM Parameter Store and tenant metadata into DynamoDB.
 *
 * Prerequisites:
 *   - AWS CLI configured (aws configure, or AWS_PROFILE env var set)
 *   - npm install @aws-sdk/client-ssm @aws-sdk/client-dynamodb @aws-sdk/lib-dynamodb
 *
 * Usage:
 *   AWS_REGION=ap-southeast-1 node scripts/migrate-to-ssm.js
 *
 * Optional: set WIDGET_API_KEY env var to use a specific key, otherwise one is generated.
 */

const { SSMClient, PutParameterCommand } = require('@aws-sdk/client-ssm');
const { DynamoDBClient } = require('@aws-sdk/client-dynamodb');
const { DynamoDBDocumentClient, PutCommand, UpdateCommand } = require('@aws-sdk/lib-dynamodb');
const { randomUUID } = require('crypto');
const fs = require('fs');
const path = require('path');

const REGION = process.env.AWS_REGION || 'ap-southeast-1';
const TENANTS_TABLE = process.env.DYNAMO_TENANTS_TABLE || 'ghl-tenants';
const ROOT = path.join(__dirname, '..');

const ssm = new SSMClient({ region: REGION });
const ddb = DynamoDBDocumentClient.from(new DynamoDBClient({ region: REGION }));

// ── Helpers ──────────────────────────────────────────────────────────────────

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

async function putParam(name, value, description) {
  for (let attempt = 0; attempt < 5; attempt++) {
    try {
      await ssm.send(new PutParameterCommand({
        Name: name,
        Value: value,
        Type: 'SecureString',
        Overwrite: true,
        Description: description,
      }));
      console.log(`  [SSM] ${name}`);
      await sleep(250); // stay well under SSM's 10 TPS PutParameter limit
      return;
    } catch (err) {
      if (err.name === 'ThrottlingException' && attempt < 4) {
        const wait = (attempt + 1) * 2000;
        console.warn(`  [SSM] throttled, retrying in ${wait / 1000}s...`);
        await sleep(wait);
      } else throw err;
    }
  }
}

async function putTenant(item) {
  await ddb.send(new PutCommand({ TableName: TENANTS_TABLE, Item: item }));
  console.log(`  [DDB] ${item.name || item.locationId} (${item.locationId})`);
}

async function updateTenantField(locationId, field, value) {
  await ddb.send(new UpdateCommand({
    TableName: TENANTS_TABLE,
    Key: { locationId },
    UpdateExpression: 'SET #f = :v',
    ExpressionAttributeNames: { '#f': field },
    ExpressionAttributeValues: { ':v': value },
  }));
}

// ── Migration steps ───────────────────────────────────────────────────────────

async function migrateGhlConfig() {
  console.log('\n=== Migrating config.json → SSM + DynamoDB ===');
  const tenants = JSON.parse(fs.readFileSync(path.join(ROOT, 'config.json'), 'utf8'));

  for (const tenant of tenants) {
    const { defaultLocationId, accessToken, name, customFieldIds = {}, phoneNumbers } = tenant;
    if (!defaultLocationId || !accessToken) continue;

    await putParam(
      `/ghl-widgets/tenants/${defaultLocationId}/access-token`,
      accessToken,
      `GHL access token for ${name}`
    );

    const item = { locationId: defaultLocationId, name, customFieldIds };
    if (phoneNumbers) item.phoneNumbers = phoneNumbers;
    await putTenant(item);
  }
}

async function migrateSarConfig() {
  const sarPath = path.join(ROOT, 'SAR_config.json');
  if (!fs.existsSync(sarPath)) return;

  console.log('\n=== Migrating SAR_config.json → SSM + DynamoDB ===');
  const entries = JSON.parse(fs.readFileSync(sarPath, 'utf8'));

  for (const entry of entries) {
    const { defaultLocationId, accessToken, name, customFieldIds = {} } = entry;
    if (!defaultLocationId || !accessToken) continue;

    // SSM: put token (will overwrite if already migrated from config.json — same value expected)
    await putParam(
      `/ghl-widgets/tenants/${defaultLocationId}/access-token`,
      accessToken,
      `GHL access token for ${name} (SAR)`
    );

    // DynamoDB: upsert with PutCommand (won't duplicate since PK is locationId)
    await putTenant({ locationId: defaultLocationId, name, customFieldIds });
  }
}

async function migrateCallConfig() {
  const callPath = path.join(ROOT, 'call_config1.json');
  if (!fs.existsSync(callPath)) return;

  console.log('\n=== Migrating call_config1.json → SSM + DynamoDB ===');
  const config = JSON.parse(fs.readFileSync(callPath, 'utf8'));

  await putParam('/ghl-widgets/call/username', config.authentication.username, 'Call CDR Basic Auth username');
  await putParam('/ghl-widgets/call/password', config.authentication.password, 'Call CDR Basic Auth password');

  for (const client of (config.clients || [])) {
    if (!client.locationId) continue;
    await updateTenantField(client.locationId, 'callClientIds', client.accounts);
    console.log(`  [DDB] callClientIds → ${client.clientname} (${client.locationId})`);
  }
}

async function setApiKey() {
  console.log('\n=== Internal API key ===');
  const key = process.env.WIDGET_API_KEY || randomUUID();
  await putParam('/ghl-widgets/api-key', key, 'Internal API key for widget → Lambda auth');

  if (!process.env.WIDGET_API_KEY) {
    console.log(`\n  *** Generated API key: ${key}`);
    console.log('  Copy this value into your frontend config (LAMBDA_API_KEY in ghlDataV2.js).\n');
  }
}

// ── Main ──────────────────────────────────────────────────────────────────────

(async () => {
  try {
    await migrateGhlConfig();
    await migrateSarConfig();
    await migrateCallConfig();
    await setApiKey();
    console.log('\n✅ Migration complete. You can now remove the JSON config files from the web root.\n');
  } catch (err) {
    console.error('\n❌ Migration failed:', err.message);
    console.error(err.stack);
    process.exit(1);
  }
})();
