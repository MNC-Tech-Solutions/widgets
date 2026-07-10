#!/usr/bin/env node
/**
 * One-time script to add OSK Shorea Park and OSK Hana Hills tenants
 * to SSM Parameter Store and DynamoDB.
 *
 * Usage:
 *   AWS_REGION=ap-southeast-1 node scripts/seed-osk-tenants.js
 */

const { SSMClient, PutParameterCommand } = require('@aws-sdk/client-ssm');
const { DynamoDBClient } = require('@aws-sdk/client-dynamodb');
const { DynamoDBDocumentClient, PutCommand } = require('@aws-sdk/lib-dynamodb');

const REGION = process.env.AWS_REGION || 'ap-southeast-1';
const TENANTS_TABLE = process.env.DYNAMO_TENANTS_TABLE || 'ghl-tenants';

const ssm = new SSMClient({ region: REGION });
const ddb = DynamoDBDocumentClient.from(new DynamoDBClient({ region: REGION }));

const TENANTS = [
  {
    accessToken: process.env.OSK_SHOREA_PARK_TOKEN,
    defaultLocationId: 'gezJBjpyd5gCnPNYe8ta',
    name: 'OSK Shorea Park',
    customFieldIds: { sourceCategory: 'zgw2EMDvd64Jcohu14cj', project: 'N/A' },
  },
  {
    accessToken: process.env.OSK_HANA_HILLS_TOKEN,
    defaultLocationId: 'qLYvS2uBNRVlTJDRXhYj',
    name: 'OSK Hana Hills',
    customFieldIds: { sourceCategory: 'M2sLKXGNg1Q5LRALxLjN', project: 'N/A' },
  },
];

const missing = TENANTS.filter(t => !t.accessToken).map(t => t.name);
if (missing.length) {
  console.error(`Missing env vars for: ${missing.join(', ')}`);
  console.error('Set OSK_SHOREA_PARK_TOKEN and OSK_HANA_HILLS_TOKEN before running.');
  process.exit(1);
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

async function putParam(name, value, description) {
  await ssm.send(new PutParameterCommand({
    Name: name,
    Value: value,
    Type: 'SecureString',
    Overwrite: true,
    Description: description,
  }));
  console.log(`  [SSM] ${name}`);
  await sleep(250);
}

async function putTenant(item) {
  await ddb.send(new PutCommand({ TableName: TENANTS_TABLE, Item: item }));
  console.log(`  [DDB] ${item.name} (${item.locationId})`);
}

(async () => {
  try {
    for (const { accessToken, defaultLocationId, name, customFieldIds } of TENANTS) {
      await putParam(
        `/ghl-widgets/tenants/${defaultLocationId}/access-token`,
        accessToken,
        `GHL access token for ${name}`
      );
      await putTenant({ locationId: defaultLocationId, name, customFieldIds });
    }
    console.log('\n✅ OSK tenants seeded successfully.\n');
  } catch (err) {
    console.error('\n❌ Failed:', err.message);
    process.exit(1);
  }
})();
