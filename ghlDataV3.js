/**
 * ghlDataV3.js — v2.0.0
 * Drop-in replacement for ghlDataV2.js that calls the Lambda backend instead of
 * the GHL API directly. Tokens never leave the server.
 *
 * SETUP: Set LAMBDA_BASE_URL and LAMBDA_API_KEY below after deploying the Lambda stack.
 */

const GHLDATA_VERSION = '2.0.0';
console.log('ghlDataV3.js loaded - version:', GHLDATA_VERSION);

// ── Configuration ─────────────────────────────────────────────────────────────
// Replace these with your actual values after running: bash lambda/deploy.sh --guided
const LAMBDA_BASE_URL = 'https://riedh3l031.execute-api.ap-southeast-1.amazonaws.com';
const LAMBDA_API_KEY  = '2d291f00-5145-4a2d-b1d8-ff13fd661f28';

// L1 cache TTL: how long IndexedDB data is treated as fresh before asking Lambda
const LOCAL_TTL_MS = 20 * 60 * 1000; // 20 minutes

// ── IndexedDB ─────────────────────────────────────────────────────────────────
const DB_NAME    = 'ghl_funnel_db';
const DB_VERSION = 3; // bumped from v2 to add cache_meta store

let dbPromise;

function openDB() {
  if (dbPromise) return dbPromise;
  dbPromise = new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);
    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve(request.result);
    request.onupgradeneeded = (event) => {
      const db = event.target.result;
      if (!db.objectStoreNames.contains('pipelines')) {
        const s = db.createObjectStore('pipelines', { keyPath: 'locationId' });
        s.createIndex('locationId', 'locationId');
      }
      if (!db.objectStoreNames.contains('users')) {
        const s = db.createObjectStore('users', { keyPath: ['locationId', 'userId'] });
        s.createIndex('locationId', 'locationId');
      }
      if (!db.objectStoreNames.contains('opportunities')) {
        const s = db.createObjectStore('opportunities', { keyPath: ['locationId', 'pipelineId', 'id'] });
        s.createIndex('locationId', 'locationId');
        s.createIndex('pipelineId', 'pipelineId');
      }
      // v3: metadata store for TTL tracking
      if (!db.objectStoreNames.contains('cache_meta')) {
        db.createObjectStore('cache_meta', { keyPath: 'key' });
      }
    };
  });
  return dbPromise;
}

function isValidKey(key) { return key !== null && key !== undefined && key !== ''; }

// ── cache_meta helpers ────────────────────────────────────────────────────────

async function getMetaFetchedAt(metaKey) {
  const db = await openDB();
  return new Promise((resolve) => {
    const tx = db.transaction('cache_meta', 'readonly');
    const req = tx.objectStore('cache_meta').get(metaKey);
    req.onsuccess = () => resolve(req.result?.fetchedAt || 0);
    req.onerror = () => resolve(0);
  });
}

async function setMetaFetchedAt(metaKey) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction('cache_meta', 'readwrite');
    tx.objectStore('cache_meta').put({ key: metaKey, fetchedAt: Date.now() });
    tx.oncomplete = resolve;
    tx.onerror = () => reject(tx.error);
  });
}

function isFresh(fetchedAt) { return Date.now() - fetchedAt < LOCAL_TTL_MS; }

// ── IndexedDB read/write (unchanged signatures) ───────────────────────────────

async function getCache(storeName, key) {
  if (!isValidKey(key)) return null;
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(storeName, 'readonly');
    const req = tx.objectStore(storeName).get(key);
    req.onsuccess = () => resolve(req.result?.data || null);
    req.onerror = () => reject(req.error);
  });
}

async function setCache(storeName, key, data) {
  if (!isValidKey(key) && storeName === 'pipelines') return;
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(storeName, 'readwrite');
    const entry = storeName === 'pipelines' ? { locationId: key, data } : { ...key, data };
    const req = tx.objectStore(storeName).put(entry);
    req.onsuccess = () => resolve();
    req.onerror = () => reject(req.error);
  });
}

async function getAllByIndex(storeName, indexName, indexValue) {
  if (!isValidKey(indexValue)) return [];
  const db = await openDB();
  return new Promise((resolve, reject) => {
    try {
      const tx = db.transaction(storeName, 'readonly');
      const idx = tx.objectStore(storeName).index(indexName);
      const req = idx.getAll(IDBKeyRange.only(indexValue));
      req.onsuccess = () => resolve(req.result.map(item => item.data));
      req.onerror = () => reject(req.error);
    } catch { resolve([]); }
  });
}

async function clearOpportunitiesCache(locationId, pipelineId) {
  if (!isValidKey(pipelineId)) return;
  const db = await openDB();
  await new Promise((resolve, reject) => {
    const tx = db.transaction('opportunities', 'readwrite');
    const req = tx.objectStore('opportunities').index('pipelineId').openCursor(IDBKeyRange.only(pipelineId));
    req.onsuccess = (e) => { const cursor = e.target.result; if (cursor) { cursor.delete(); cursor.continue(); } };
    tx.oncomplete = resolve;
    tx.onerror = () => reject(tx.error);
  });
  // Clear meta TTL so next read knows to re-fetch
  const db2 = await openDB();
  await new Promise((resolve) => {
    const tx = db2.transaction('cache_meta', 'readwrite');
    tx.objectStore('cache_meta').delete(`opportunities#${locationId}#${pipelineId}`);
    tx.oncomplete = resolve;
    tx.onerror = resolve;
  });
}

async function clearIndexedDB() {
  const db = await openDB();
  const tx = db.transaction(['pipelines', 'users', 'opportunities', 'cache_meta'], 'readwrite');
  ['pipelines', 'users', 'opportunities', 'cache_meta'].forEach(s => tx.objectStore(s).clear());
  return new Promise(resolve => { tx.oncomplete = resolve; });
}

// ── Utility ───────────────────────────────────────────────────────────────────

function getLocationId() {
  const urlParams = new URLSearchParams(window.location.search);
  const locId = urlParams.get('locationId');
  if (locId) return locId;
  try {
    const match = window.parent.location.href.match(/\/location\/([^/]+)\//);
    return match?.[1] || null;
  } catch { return null; }
}

function formatDateTimeWithOffset(utcDateString) {
  if (!utcDateString) return 'N/A';
  const d = new Date(utcDateString);
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

function pad(n) { return String(n).padStart(2, '0'); }

function applyFilters(opportunities, pipelineId, startDate, endDate, hiddenProjects = new Set(), additionalFilters = {}) {
  let f = opportunities;
  if (pipelineId) f = f.filter(op => op.pipelineId === pipelineId);
  if (startDate) {
    const start = new Date(startDate.includes(' ') ? startDate : `${startDate} 00:00:00`);
    f = f.filter(op => new Date(op.createdAt) >= start);
  }
  if (endDate) {
    const end = new Date(endDate.includes(' ') ? endDate : `${endDate} 23:59:59.999`);
    f = f.filter(op => new Date(op.createdAt) <= end);
  }
  if (additionalFilters.project) f = f.filter(op => op.project === additionalFilters.project);
  if (additionalFilters.channels?.length > 0 && !additionalFilters.channels.includes('all'))
    f = f.filter(op => op.source && additionalFilters.channels.includes(op.source));
  if (additionalFilters.sourceCategories?.length > 0)
    f = f.filter(op => additionalFilters.sourceCategories.includes(op.sourceCategory));
  if (additionalFilters.agents?.length > 0)
    f = f.filter(op => additionalFilters.agents.includes(op.assignedTo));
  if (additionalFilters.teams?.length > 0)
    f = f.filter(op => additionalFilters.teams.includes(op.team));
  return f;
}

// ── Progress modal (unchanged) ────────────────────────────────────────────────

class ProgressModalManager {
  constructor() { this.modal = null; this.progressBar = null; this.statusText = null; this.percentageText = null; this.isInitialized = false; }

  initialize() {
    if (this.isInitialized) return;
    const html = `
      <div class="modal fade" id="progressModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header border-0"><h5 class="modal-title">Fetching Data</h5></div>
            <div class="modal-body">
              <div class="mb-3">
                <div class="progress" style="height:24px;border-radius:8px;background:#f0f0f0;overflow:hidden;">
                  <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%;background:linear-gradient(90deg,#91AEC4,#2667cc);" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <div style="text-align:center;margin-top:8px;"><span id="percentageText" style="font-weight:600;color:#374151;font-size:14px;">0%</span></div>
              </div>
              <div id="statusText" style="text-align:center;color:#6b7280;font-size:14px;line-height:1.5;min-height:60px;"><p style="margin:0;">Syncing data...</p></div>
              <div style="background:#f8f9fa;padding:12px;border-radius:8px;margin-top:16px;border-left:4px solid #91AEC4;">
                <p style="margin:0;font-size:13px;color:#6b7280;"><strong style="color:#374151;">ℹ️ Multi-Widget Sync:</strong> Data will sync to other open widgets automatically.</p>
              </div>
            </div>
          </div>
        </div>
      </div>`;
    const w = document.createElement('div'); w.innerHTML = html;
    document.body.appendChild(w.firstElementChild);
    this.modal = new bootstrap.Modal(document.getElementById('progressModal'), { backdrop: 'static', keyboard: false });
    this.progressBar = document.getElementById('progressBar');
    this.statusText  = document.getElementById('statusText');
    this.percentageText = document.getElementById('percentageText');
    this.isInitialized = true;
  }

  show(status = 'Loading...') { this.initialize(); this.setProgress(0, status); this.modal.show(); }

  setProgress(pct, status) {
    if (!this.isInitialized) return;
    if (pct !== null) {
      const p = Math.min(Math.max(pct, 0), 100);
      this.progressBar.style.width = p + '%';
      this.progressBar.setAttribute('aria-valuenow', p);
      this.percentageText.textContent = p + '%';
    }
    if (status) this.statusText.innerHTML = `<p style="margin:0;">${status}</p>`;
  }

  hide() { if (this.isInitialized && this.modal) this.modal.hide(); }
  destroy() { if (this.isInitialized && this.modal) { this.modal.dispose(); this.isInitialized = false; } }
}

const progressManager = new ProgressModalManager();

// ── Lambda fetch helper ───────────────────────────────────────────────────────

async function lambdaFetch(path, options = {}) {
  const url = `${LAMBDA_BASE_URL}${path}`;
  const res = await fetch(url, {
    ...options,
    headers: { 'X-Api-Key': LAMBDA_API_KEY, 'Content-Type': 'application/json', ...options.headers },
  });
  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`Lambda ${res.status} at ${path}: ${text}`);
  }
  return res.json();
}

// ── Public API — same signatures as v1, no accessToken needed ─────────────────

async function loadConfig() {
  try {
    const locationId = getLocationId();
    if (!locationId) return { config: null, locationId: null };
    const config = await lambdaFetch(`/config?locationId=${locationId}`);
    return { config, locationId };
  } catch (err) {
    console.error('loadConfig error:', err);
    return { config: null, locationId: null };
  }
}

async function fetchPipelinesV2(_config, locationId) {
  const metaKey = `pipelines#${locationId}`;
  const fetchedAt = await getMetaFetchedAt(metaKey);

  if (isFresh(fetchedAt)) {
    const cached = await getCache('pipelines', locationId);
    if (cached) return cached;
  }

  try {
    const pipelines = await lambdaFetch(`/ghl/pipelines?locationId=${locationId}`);
    await setCache('pipelines', locationId, pipelines);
    await setMetaFetchedAt(metaKey);
    return pipelines;
  } catch (err) {
    console.error('fetchPipelinesV2 error:', err);
    const cached = await getCache('pipelines', locationId);
    return cached || [];
  }
}

async function fetchAllUsers(_config, locationId) {
  const metaKey = `users#${locationId}`;
  const fetchedAt = await getMetaFetchedAt(metaKey);

  if (isFresh(fetchedAt)) {
    const cached = await getAllByIndex('users', 'locationId', locationId);
    if (cached.length > 0) return cached;
  }

  try {
    const users = await lambdaFetch(`/ghl/users?locationId=${locationId}`);
    const db = await openDB();
    const tx = db.transaction('users', 'readwrite');
    const store = tx.objectStore('users');
    users.forEach(u => store.put({ locationId, userId: u.id, data: u }));
    await new Promise((resolve, reject) => { tx.oncomplete = resolve; tx.onerror = () => reject(tx.error); });
    await setMetaFetchedAt(metaKey);
    return users;
  } catch (err) {
    console.error('fetchAllUsers error:', err);
    return await getAllByIndex('users', 'locationId', locationId);
  }
}

async function fetchAllOpportunities(_config, locationId, pipelineId, forceRefresh = false, showProgress = false) {
  if (!isValidKey(pipelineId)) return [];

  const metaKey = `opportunities#${locationId}#${pipelineId}`;

  if (forceRefresh) {
    // Invalidate server cache + local cache
    await lambdaFetch(`/ghl/cache?locationId=${locationId}&resource=opportunities&pipelineId=${pipelineId}`, { method: 'DELETE' }).catch(() => {});
    await clearOpportunitiesCache(locationId, pipelineId);
  } else {
    const fetchedAt = await getMetaFetchedAt(metaKey);
    if (isFresh(fetchedAt)) {
      const cached = await getAllByIndex('opportunities', 'pipelineId', pipelineId);
      if (cached.length > 0) return cached;
    }
  }

  if (showProgress) progressManager.show('Loading opportunities...');

  try {
    if (showProgress) progressManager.setProgress(20, 'Fetching from server...');
    const opps = await lambdaFetch(`/ghl/opportunities?locationId=${locationId}&pipelineId=${pipelineId}`);
    if (showProgress) progressManager.setProgress(70, 'Caching locally...');

    const db = await openDB();
    const tx = db.transaction('opportunities', 'readwrite');
    const store = tx.objectStore('opportunities');
    opps.forEach(op => store.put({ locationId, pipelineId: op.pipelineId, id: op.id, data: op }));
    await new Promise((resolve, reject) => { tx.oncomplete = resolve; tx.onerror = () => reject(tx.error); });

    await setMetaFetchedAt(metaKey);

    if (showProgress) {
      progressManager.setProgress(100, 'Complete!');
      localStorage.setItem('ghl_last_refresh', Date.now().toString());
      setTimeout(() => progressManager.hide(), 800);
    }
    return opps;
  } catch (err) {
    console.error('fetchAllOpportunities error:', err);
    if (showProgress) progressManager.hide();
    return await getAllByIndex('opportunities', 'pipelineId', pipelineId);
  }
}

// fetchNewOpportunities: simplified — Lambda handles freshness via cache warmer.
// Returns newly available opps (those not yet in local IndexedDB).
async function fetchNewOpportunities(_config, locationId, pipelineId) {
  if (!isValidKey(pipelineId)) return [];
  try {
    const cachedIds = new Set((await getAllByIndex('opportunities', 'pipelineId', pipelineId)).map(op => op.id));
    const opps = await lambdaFetch(`/ghl/opportunities?locationId=${locationId}&pipelineId=${pipelineId}`);
    const newOpps = opps.filter(op => !cachedIds.has(op.id));

    if (newOpps.length > 0) {
      const db = await openDB();
      const tx = db.transaction('opportunities', 'readwrite');
      const store = tx.objectStore('opportunities');
      newOpps.forEach(op => store.put({ locationId, pipelineId: op.pipelineId, id: op.id, data: op }));
      await new Promise((resolve, reject) => { tx.oncomplete = resolve; tx.onerror = () => reject(tx.error); });
    }
    return newOpps;
  } catch (err) {
    console.error('fetchNewOpportunities error:', err);
    return [];
  }
}

export {
  openDB, getCache, setCache, getAllByIndex, clearOpportunitiesCache,
  getLocationId, loadConfig, fetchPipelinesV2, fetchAllOpportunities,
  formatDateTimeWithOffset, applyFilters, clearIndexedDB, progressManager,
  fetchNewOpportunities, fetchAllUsers,
};
