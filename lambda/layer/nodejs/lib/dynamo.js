const { DynamoDBClient } = require('@aws-sdk/client-dynamodb');
const {
  DynamoDBDocumentClient,
  GetCommand,
  PutCommand,
  DeleteCommand,
  UpdateCommand,
  ScanCommand,
  QueryCommand,
  BatchWriteCommand,
} = require('@aws-sdk/lib-dynamodb');
const { S3Client, GetObjectCommand, PutObjectCommand, DeleteObjectCommand } = require('@aws-sdk/client-s3');

const client = new DynamoDBClient({
  ...(process.env.DYNAMO_ENDPOINT && { endpoint: process.env.DYNAMO_ENDPOINT }),
});
const ddb = DynamoDBDocumentClient.from(client);
const s3 = new S3Client({});

const CACHE_TABLE = process.env.DYNAMO_CACHE_TABLE || 'ghl-cache';
const TENANTS_TABLE = process.env.DYNAMO_TENANTS_TABLE || 'ghl-tenants';
const OVERFLOW_BUCKET = process.env.CACHE_OVERFLOW_BUCKET || 'mnc-ghl-cache-overflow';
const DYNAMO_SIZE_LIMIT = 380 * 1024; // 380KB — stay under DynamoDB's 400KB item limit

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

  if (item.storageRef) {
    // Large payload was spilled to S3
    try {
      const s3Res = await s3.send(new GetObjectCommand({ Bucket: OVERFLOW_BUCKET, Key: item.storageRef }));
      const body = await s3Res.Body.transformToString();
      return JSON.parse(body);
    } catch (e) {
      // S3 object expired or missing — treat as cache miss so Lambda re-fetches
      return null;
    }
  }

  return JSON.parse(item.data);
}

async function setCached(pk, sk, data) {
  const json = JSON.stringify(data);
  const ttl = Math.floor(Date.now() / 1000) + ttlFor(sk);

  if (Buffer.byteLength(json, 'utf8') > DYNAMO_SIZE_LIMIT) {
    // Payload too large for DynamoDB — store in S3, keep pointer in DynamoDB
    const s3Key = `cache/${pk.replace(/#/g, '_')}/${sk.replace(/#/g, '_')}.json`;
    await s3.send(new PutObjectCommand({
      Bucket: OVERFLOW_BUCKET,
      Key: s3Key,
      Body: json,
      ContentType: 'application/json',
    }));
    await ddb.send(new PutCommand({
      TableName: CACHE_TABLE,
      Item: { pk, sk, storageRef: s3Key, fetchedAt: Date.now(), ttl },
    }));
  } else {
    await ddb.send(new PutCommand({
      TableName: CACHE_TABLE,
      Item: { pk, sk, data: json, fetchedAt: Date.now(), ttl },
    }));
  }
}

async function deleteCached(pk, sk) {
  // Clean up S3 object if present
  const result = await ddb.send(new GetCommand({ TableName: CACHE_TABLE, Key: { pk, sk } }));
  if (result.Item?.storageRef) {
    await s3.send(new DeleteObjectCommand({ Bucket: OVERFLOW_BUCKET, Key: result.Item.storageRef })).catch(() => {});
  }
  await ddb.send(new DeleteCommand({ TableName: CACHE_TABLE, Key: { pk, sk } }));
}

async function deleteAllCached(pk) {
  let lastKey;
  do {
    const result = await ddb.send(new QueryCommand({
      TableName: CACHE_TABLE,
      KeyConditionExpression: 'pk = :pk',
      ExpressionAttributeValues: { ':pk': pk },
      ProjectionExpression: 'pk, sk, storageRef',
      ...(lastKey && { ExclusiveStartKey: lastKey }),
    }));
    const items = result.Items || [];
    if (!items.length) break;

    // Clean up any S3 overflow objects
    await Promise.all(items
      .filter(i => i.storageRef)
      .map(i => s3.send(new DeleteObjectCommand({ Bucket: OVERFLOW_BUCKET, Key: i.storageRef })).catch(() => {}))
    );

    // Batch delete DynamoDB items (max 25 per request)
    for (let i = 0; i < items.length; i += 25) {
      const chunk = items.slice(i, i + 25);
      await ddb.send(new BatchWriteCommand({
        RequestItems: {
          [CACHE_TABLE]: chunk.map(({ pk, sk }) => ({ DeleteRequest: { Key: { pk, sk } } })),
        },
      }));
    }
    lastKey = result.LastEvaluatedKey;
  } while (lastKey);
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

module.exports = { getCached, setCached, deleteCached, deleteAllCached, getTenant, getAllTenants, updateTenant, TTL_SECONDS };
