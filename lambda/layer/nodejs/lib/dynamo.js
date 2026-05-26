const { DynamoDBClient } = require('@aws-sdk/client-dynamodb');
const {
  DynamoDBDocumentClient,
  GetCommand,
  PutCommand,
  DeleteCommand,
  UpdateCommand,
  ScanCommand,
} = require('@aws-sdk/lib-dynamodb');

const client = new DynamoDBClient({
  ...(process.env.DYNAMO_ENDPOINT && { endpoint: process.env.DYNAMO_ENDPOINT }),
});
const ddb = DynamoDBDocumentClient.from(client);

const CACHE_TABLE = process.env.DYNAMO_CACHE_TABLE || 'ghl-cache';
const TENANTS_TABLE = process.env.DYNAMO_TENANTS_TABLE || 'ghl-tenants';

const TTL_SECONDS = {
  pipelines: 24 * 60 * 60,
  users: 6 * 60 * 60,
  opportunities: 20 * 60,
  conversations: 20 * 60,
  contacts: 15 * 60,
  calendar: 10 * 60,
  notes: 30 * 60,
  cdr: 20 * 60,
};

function ttlFor(sk) {
  const resource = sk.split('#')[0];
  return TTL_SECONDS[resource] || 20 * 60;
}

async function getCached(pk, sk) {
  const result = await ddb.send(new GetCommand({ TableName: CACHE_TABLE, Key: { pk, sk } }));
  const item = result.Item;
  if (!item) return null;
  // Manual TTL check in case DynamoDB hasn't swept the item yet
  if (item.ttl && item.ttl < Math.floor(Date.now() / 1000)) return null;
  return JSON.parse(item.data);
}

async function setCached(pk, sk, data) {
  await ddb.send(new PutCommand({
    TableName: CACHE_TABLE,
    Item: {
      pk,
      sk,
      data: JSON.stringify(data),
      fetchedAt: Date.now(),
      ttl: Math.floor(Date.now() / 1000) + ttlFor(sk),
    },
  }));
}

async function deleteCached(pk, sk) {
  await ddb.send(new DeleteCommand({ TableName: CACHE_TABLE, Key: { pk, sk } }));
}

async function getTenant(locationId) {
  const result = await ddb.send(new GetCommand({ TableName: TENANTS_TABLE, Key: { locationId } }));
  return result.Item || null;
}

async function getAllTenants() {
  const result = await ddb.send(new ScanCommand({ TableName: TENANTS_TABLE }));
  return result.Items || [];
}

async function updateTenant(locationId, updates) {
  const entries = Object.entries(updates);
  const expr = 'SET ' + entries.map((_, i) => `#k${i} = :v${i}`).join(', ');
  const names = Object.fromEntries(entries.map(([k], i) => [`#k${i}`, k]));
  const values = Object.fromEntries(entries.map(([, v], i) => [`:v${i}`, v]));

  await ddb.send(new UpdateCommand({
    TableName: TENANTS_TABLE,
    Key: { locationId },
    UpdateExpression: expr,
    ExpressionAttributeNames: names,
    ExpressionAttributeValues: values,
  }));
}

module.exports = { getCached, setCached, deleteCached, getTenant, getAllTenants, updateTenant, TTL_SECONDS };
