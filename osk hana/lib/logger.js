const fs = require('fs');
const fsp = fs.promises;
const path = require('path');
const zlib = require('zlib');
const { pipeline } = require('stream/promises');
const config = require('../config');
const mailer = require('./mailer');

const LOG_DIR = path.join(__dirname, '..', 'logs');
const ARCHIVE_DIR = path.join(LOG_DIR, 'archive');
const CURRENT_LOG_PATH = path.join(LOG_DIR, 'current.log');

let writeStream = null;
let bytesWritten = 0;
let activeStart = null;
let diskFull = false;
let diskCheckTimer = null;
let diskFullAlertSentAt = 0;

// Serializes all writes + rotations so a rotation never races a concurrent
// write against the stream it's in the middle of swapping out.
let chain = Promise.resolve();
function enqueue(task) {
  const next = chain.then(task, (err) => {
    console.error('[logger] previous task failed', err);
    return task();
  });
  chain = next;
  return next;
}

function fmtTimestamp(date) {
  const pad = (n) => String(n).padStart(2, '0');
  return (
    `${date.getUTCFullYear()}${pad(date.getUTCMonth() + 1)}${pad(date.getUTCDate())}` +
    `-${pad(date.getUTCHours())}${pad(date.getUTCMinutes())}${pad(date.getUTCSeconds())}`
  );
}

async function init() {
  await fsp.mkdir(ARCHIVE_DIR, { recursive: true });

  let startTime = new Date();
  let existingSize = 0;
  try {
    const stat = await fsp.stat(CURRENT_LOG_PATH);
    existingSize = stat.size;
    if (stat.birthtime && stat.birthtime.getTime() > 0) startTime = stat.birthtime;
  } catch (err) {
    if (err.code !== 'ENOENT') throw err;
  }

  activeStart = startTime;
  bytesWritten = existingSize;
  writeStream = fs.createWriteStream(CURRENT_LOG_PATH, { flags: 'a' });

  await checkDiskSpace();
  diskCheckTimer = setInterval(() => checkDiskSpace().catch((err) => console.error('[logger] disk check failed', err)), 30000);
  diskCheckTimer.unref();
}

async function checkDiskSpace() {
  const stat = await fsp.statfs(LOG_DIR);
  const freeBytes = stat.bavail * stat.bsize;
  const wasFull = diskFull;
  diskFull = freeBytes < config.diskFreeFloorBytes;

  if (diskFull && !wasFull) {
    const now = Date.now();
    if (now - diskFullAlertSentAt > 30 * 60 * 1000) {
      diskFullAlertSentAt = now;
      mailer
        .sendAlert(
          'osk: disk space low, writes paused',
          `Free disk space is ${freeBytes} bytes, below the configured floor of ${config.diskFreeFloorBytes} bytes.\nIncoming webhook writes are being rejected until space is freed.`
        )
        .catch((err) => console.error('[mailer] disk-space alert failed', err));
    }
  }
}

async function write(record) {
  if (diskFull) {
    throw new Error('disk space below configured floor, writes paused');
  }
  const buf = Buffer.from(JSON.stringify(record) + '\n', 'utf8');
  return enqueue(async () => {
    await new Promise((resolve, reject) => {
      writeStream.write(buf, (err) => (err ? reject(err) : resolve()));
    });
    bytesWritten += buf.length;
    if (bytesWritten >= config.rotateSizeBytes) {
      await rotate();
    }
  });
}

async function rotate() {
  const start = activeStart;
  const end = new Date();
  const oldStream = writeStream;
  const rotatedPath = path.join(ARCHIVE_DIR, `requests_${fmtTimestamp(start)}_${fmtTimestamp(end)}.log`);

  await new Promise((resolve, reject) => {
    oldStream.end((err) => (err ? reject(err) : resolve()));
  });
  await fsp.rename(CURRENT_LOG_PATH, rotatedPath);

  activeStart = new Date();
  bytesWritten = 0;
  writeStream = fs.createWriteStream(CURRENT_LOG_PATH, { flags: 'a' });

  gzipAndCleanup(rotatedPath).catch((err) => {
    console.error('[logger] gzip failed for', rotatedPath, err);
    mailer
      .sendAlert('osk: log rotation gzip failed', `Failed to gzip ${rotatedPath}: ${err.message}`)
      .catch(() => {});
  });
}

async function gzipAndCleanup(filePath) {
  const gzPath = `${filePath}.gz`;
  await pipeline(fs.createReadStream(filePath), zlib.createGzip(), fs.createWriteStream(gzPath));
  await fsp.unlink(filePath);
}

async function shutdown() {
  if (diskCheckTimer) clearInterval(diskCheckTimer);
  await chain.catch(() => {});
  if (writeStream) {
    await new Promise((resolve) => writeStream.end(resolve));
  }
}

module.exports = { init, write, shutdown };
