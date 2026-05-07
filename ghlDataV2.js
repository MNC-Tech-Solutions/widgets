const GHLDATA_VERSION = '1.0.5';
console.log('ghlDataV2.js loaded - version: ', GHLDATA_VERSION);

const DB_NAME = 'ghl_funnel_db';
const DB_VERSION = 2;

let dbPromise;

// ============ PROGRESS MODAL MANAGER ============
class ProgressModalManager {
  constructor() {
    this.modal = null;
    this.progressBar = null;
    this.statusText = null;
    this.percentageText = null;
    this.isInitialized = false;
  }

  initialize() {
    if (this.isInitialized) return;

    const modalHTML = `
      <div class="modal fade" id="progressModal" tabindex="-1" aria-labelledby="progressModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header border-0">
              <h5 class="modal-title" id="progressModalLabel">Fetching Data</h5>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <div class="progress" style="height: 24px; border-radius: 8px; background: #f0f0f0; overflow: hidden;">
                  <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%; background: linear-gradient(90deg, #91AEC4, #2667cc);" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <div style="text-align: center; margin-top: 8px;">
                  <span id="percentageText" style="font-weight: 600; color: #374151; font-size: 14px;">0%</span>
                </div>
              </div>
              <div id="statusText" style="text-align: center; color: #6b7280; font-size: 14px; line-height: 1.5; min-height: 60px;">
                <p style="margin: 0;">Syncing opportunities data...</p>
              </div>
              <div style="background: #f8f9fa; padding: 12px; border-radius: 8px; margin-top: 16px; border-left: 4px solid #91AEC4;">
                <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.4;">
                  <strong style="color: #374151;">ℹ️ Multi-Widget Sync:</strong> Data changes will automatically sync to other open widgets. Please do not refresh again.
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>
    `;

    const container = document.body;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = modalHTML;
    container.appendChild(wrapper.firstElementChild);

    this.modal = new bootstrap.Modal(document.getElementById('progressModal'), {
      backdrop: 'static',
      keyboard: false
    });

    this.progressBar = document.getElementById('progressBar');
    this.statusText = document.getElementById('statusText');
    this.percentageText = document.getElementById('percentageText');
    this.isInitialized = true;
  }

  show(initialStatus = 'Fetching opportunities data...') {
    this.initialize();
    this.setProgress(0, initialStatus);
    this.modal.show();
  }

  setProgress(percentage, status) {
    if (!this.isInitialized) return;
    
    if (percentage !== null) {
        const boundedPercentage = Math.min(Math.max(percentage, 0), 100);
        this.progressBar.style.width = boundedPercentage + '%';
        this.progressBar.setAttribute('aria-valuenow', boundedPercentage);
        this.percentageText.textContent = boundedPercentage + '%';
    }

    if (status) {
      this.statusText.innerHTML = `<p style="margin: 0;">${status}</p>`;
    }
  }

  hide() {
    if (this.isInitialized && this.modal) {
      this.modal.hide();
    }
  }

  destroy() {
    if (this.isInitialized && this.modal) {
      this.modal.dispose();
    }
    this.isInitialized = false;
  }
}

const progressManager = new ProgressModalManager();

// ============ UTILITY FUNCTIONS ============
/**
 * Helper to handle API fetches with retries on 429 errors.
 */
async function fetchWithRetry(url, options, retryDelays = [30000, 60000]) {
  for (let i = 0; i <= retryDelays.length; i++) {
    const response = await fetch(url, options);
    
    if (response.status === 429 && i < retryDelays.length) {
      const waitTime = retryDelays[i];
      const seconds = waitTime / 1000;
      console.warn(`Rate limited (429). Retrying in ${seconds}s... (Attempt ${i + 1})`);
      
      if (progressManager.isInitialized) {
        progressManager.setProgress(null, `API Rate Limited. Retrying in ${seconds}s...`);
      }
      
      await new Promise(resolve => setTimeout(resolve, waitTime));
      continue;
    }
    return response;
  }
}

// ============ DATABASE FUNCTIONS ============
function openDB() {
  if (dbPromise) return dbPromise;
  dbPromise = new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);
    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve(request.result);
    request.onupgradeneeded = (event) => {
      const db = event.target.result;
      if (!db.objectStoreNames.contains('pipelines')) {
        const pipelinesStore = db.createObjectStore('pipelines', { keyPath: 'locationId' });
        pipelinesStore.createIndex('locationId', 'locationId');
      }
      if (!db.objectStoreNames.contains('users')) {
        const usersStore = db.createObjectStore('users', { keyPath: ['locationId', 'userId'] });
        usersStore.createIndex('locationId', 'locationId');
      }
      if (!db.objectStoreNames.contains('opportunities')) {
        const opportunitiesStore = db.createObjectStore('opportunities', { keyPath: ['locationId', 'pipelineId', 'id'] });
        opportunitiesStore.createIndex('locationId', 'locationId');
        opportunitiesStore.createIndex('pipelineId', 'pipelineId');
      }
    };
  });
  return dbPromise;
}

function isValidKey(key) {
  return key !== null && key !== undefined && key !== '';
}

async function getCache(storeName, key) {
  if (!isValidKey(key)) return null;
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const transaction = db.transaction(storeName, 'readonly');
    const store = transaction.objectStore(storeName);
    const request = store.get(key);
    request.onsuccess = () => resolve(request.result?.data || null);
    request.onerror = () => reject(request.error);
  });
}

async function setCache(storeName, key, data) {
  if (!isValidKey(key) && storeName === 'pipelines') return;
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const transaction = db.transaction(storeName, 'readwrite');
    const store = transaction.objectStore(storeName);
    const cacheEntry = storeName === 'pipelines' ? { locationId: key, data } : { ...key, data };
    const request = store.put(cacheEntry);
    request.onsuccess = () => resolve();
    request.onerror = () => reject(request.error);
  });
}

async function getAllByIndex(storeName, indexName, indexValue) {
  if (!isValidKey(indexValue)) return [];
  const db = await openDB();
  return new Promise((resolve, reject) => {
    try {
      const transaction = db.transaction(storeName, 'readonly');
      const store = transaction.objectStore(storeName);
      const index = store.index(indexName);
      const request = index.getAll(IDBKeyRange.only(indexValue));
      request.onsuccess = () => resolve(request.result.map(item => item.data));
      request.onerror = () => reject(request.error);
    } catch (e) {
      resolve([]);
    }
  });
}

async function clearOpportunitiesCache(locationId, pipelineId) {
  if (!isValidKey(pipelineId)) return;
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const transaction = db.transaction('opportunities', 'readwrite');
    const store = transaction.objectStore('opportunities');
    const index = store.index('pipelineId');
    const request = index.openCursor(IDBKeyRange.only(pipelineId));
    request.onsuccess = (event) => {
      const cursor = event.target.result;
      if (cursor) { cursor.delete(); cursor.continue(); }
    };
    transaction.oncomplete = () => resolve();
    transaction.onerror = () => reject(transaction.error);
  });
}

function getLocationId() {
  const urlParams = new URLSearchParams(window.location.search);
  let locId = urlParams.get('locationId');
  if (locId) return locId;
  try {
    const match = window.parent.location.href.match(/\/location\/([^/]+)\//);
    return match && match[1] ? match[1] : null;
  } catch (e) { return null; }
}

async function loadConfig() {
  try {
    const response = await fetch(`../config.json?v=${Date.now()}`, { cache: 'no-store' });
    if (!response.ok) throw new Error(`Failed to load config: ${response.status}`);
    const configs = await response.json();
    const locationId = getLocationId();
    const config = configs.find(c => c.defaultLocationId === locationId) || {};
    return { config, locationId };
  } catch (error) {
    console.error('Error loading config:', error);
    return { config: null, locationId: null };
  }
}

async function fetchPipelinesV2(config, locationId) {
  const cachedPipelines = await getCache('pipelines', locationId);
  if (cachedPipelines) return cachedPipelines;

  try {
    const response = await fetchWithRetry(`https://services.leadconnectorhq.com/opportunities/pipelines?locationId=${locationId}`, {
      headers: { 'Authorization': `Bearer ${config.accessToken}`, 'Version': '2021-07-28' }
    });
    if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);
    const data = await response.json();
    const pipelines = data.pipelines || [];
    await setCache('pipelines', locationId, pipelines);
    return pipelines;
  } catch (error) {
    console.error('Error fetching pipelines:', error);
    return [];
  }
}

async function fetchAllUsers(config, locationId) {
  const cachedUsers = await getAllByIndex('users', 'locationId', locationId);
  if (cachedUsers.length > 0) {
    return cachedUsers;
  }

  try {
    const response = await fetch(`https://services.leadconnectorhq.com/users/?locationId=${locationId}`, {
      headers: { 
        'Authorization': `Bearer ${config.accessToken}`, 
        'Version': '2021-07-28' 
      }
    });
    if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);
    const data = await response.json();
    const db = await openDB();
    const transaction = db.transaction('users', 'readwrite');
    const store = transaction.objectStore('users');
    const users = data.users.map(user => {
      const name = `${user.firstName} ${user.lastName}`.trim() || user.email;
      const userData = { id: user.id, name, email: user.email };
      store.put({
        locationId,
        userId: user.id,
        data: userData
      });
      return userData;
    });
    await new Promise((resolve, reject) => {
      transaction.oncomplete = resolve;
      transaction.onerror = () => reject(transaction.error);
    });
    return users;
  } catch (error) {
    console.error('Error fetching users:', error);
    return [];
  }
}

async function fetchAllOpportunities(config, locationId, pipelineId, forceRefresh = false, showProgress = false) {
  if (!forceRefresh) {
    const cached = await getAllByIndex('opportunities', 'pipelineId', pipelineId);
    if (cached.length > 0) return cached;
  } else if (pipelineId) {
    await clearOpportunitiesCache(locationId, pipelineId);
  }

  if (showProgress) progressManager.show('Fetching opportunities...');

  let allOpportunities = [];
  let startAfter = null;
  let startAfterId = null;
  let hasMore = true;
  const limit = 100;

  try {
    while (hasMore) {
      let url = `https://services.leadconnectorhq.com/opportunities/search?location_id=${locationId}&limit=${limit}`;
      if (pipelineId) url += `&pipeline_id=${pipelineId}`;
      if (startAfter && startAfterId) url += `&startAfter=${startAfter}&startAfterId=${startAfterId}`;

      const response = await fetchWithRetry(url, {
        headers: { 'Authorization': `Bearer ${config.accessToken}`, 'Version': '2021-07-28' }
      });
      if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);
      const data = await response.json();

      const batch = data.opportunities || [];
      allOpportunities = allOpportunities.concat(batch);

      startAfter = data.meta?.startAfter || null;
      startAfterId = data.meta?.startAfterId || null;
      hasMore = !!startAfter && !!startAfterId && allOpportunities.length < (data.meta?.total || 0);
      
      if (showProgress) progressManager.setProgress(Math.min(90, (allOpportunities.length / (data.meta?.total || 100)) * 100), `Fetched ${allOpportunities.length} opportunities...`);
    }

    const processed = allOpportunities.map(op => {
      const customFields = op.customFields || [];
      return {
        id: op.id,
        pipelineId: op.pipelineId,
        pipelineStageId: op.pipelineStageId,
        name: op.name,
        assignedTo: op.assignedTo,
        status: op.status,
        source: op.source,
        createdAt: op.createdAt,
        sourceCategory: customFields.find(cf => cf.id === config.customFieldIds?.sourceCategory)?.fieldValueString || 'N/A',
        project: customFields.find(cf => cf.id === config.customFieldIds?.project)?.fieldValueString || 'N/A',
        team: customFields.find(cf => cf.id === config.customFieldIds?.team)?.fieldValueString || 'N/A',
        contact: op.contact || { name: 'N/A', phone: 'N/A' },
        monetaryValue: op.monetaryValue || 0
      };
    });

    const db = await openDB();
    const transaction = db.transaction('opportunities', 'readwrite');
    const store = transaction.objectStore('opportunities');
    processed.forEach(op => store.put({ locationId, pipelineId: op.pipelineId, id: op.id, data: op }));
    
    if (showProgress) {
        progressManager.setProgress(100, 'Sync complete!');
        localStorage.setItem('ghl_last_refresh', Date.now().toString());
        setTimeout(() => progressManager.hide(), 800);
    }
    return processed;
  } catch (error) {
    console.error('Error fetching opportunities:', error);
    if (showProgress) progressManager.hide();
    return [];
  }
}

async function fetchNewOpportunities(config, locationId, pipelineId) {
  if (!isValidKey(pipelineId)) return [];
  try {
    const cachedOpps = await getAllByIndex('opportunities', 'pipelineId', pipelineId);
    const cachedIds = new Set(cachedOpps.map(op => op.id));

    let newOpportunities = [];
    let startAfter = null;
    let startAfterId = null;
    let hasMore = true;
    const limit = 100;
    let batchCount = 0;

    while (hasMore && batchCount < 100) {
      batchCount++;
      let url = `https://services.leadconnectorhq.com/opportunities/search?location_id=${locationId}&limit=${limit}&pipeline_id=${pipelineId}`;
      if (startAfter && startAfterId) url += `&startAfter=${startAfter}&startAfterId=${startAfterId}`;

      const response = await fetch(url, {
        headers: { 'Authorization': `Bearer ${config.accessToken}`, 'Version': '2021-07-28' }
      });
      if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);
      const data = await response.json();

      const batchOpps = data.opportunities || [];
      const trulyNewOpps = batchOpps.filter(op => !cachedIds.has(op.id));
      
      if (trulyNewOpps.length > 0) {
        newOpportunities = newOpportunities.concat(trulyNewOpps);
      } else {
        break; 
      }

      startAfter = data.meta?.startAfter || null;
      startAfterId = data.meta?.startAfterId || null;
      hasMore = !!startAfter && !!startAfterId;
    }

    if (newOpportunities.length === 0) return [];

    const processedOpportunities = newOpportunities.map(op => {
      const customFields = op.customFields || [];
      return {
        id: op.id,
        pipelineId: op.pipelineId,
        pipelineStageId: op.pipelineStageId,
        name: op.name,
        assignedTo: op.assignedTo,
        status: op.status,
        source: op.source,
        createdAt: op.createdAt,
        sourceCategory: customFields.find(cf => cf.id === config.customFieldIds?.sourceCategory)?.fieldValueString || 'N/A',
        project: customFields.find(cf => cf.id === config.customFieldIds?.project)?.fieldValueString || 'N/A',
        team: customFields.find(cf => cf.id === config.customFieldIds?.team)?.fieldValueString || 'N/A',
        contact: op.contact || { name: 'N/A', phone: 'N/A' },
        monetaryValue: op.monetaryValue || 0
      };
    });

    const db = await openDB();
    const transaction = db.transaction('opportunities', 'readwrite');
    const store = transaction.objectStore('opportunities');
    processedOpportunities.forEach(op => {
      store.put({ locationId, pipelineId: op.pipelineId, id: op.id, data: op });
    });
    
    return processedOpportunities;
  } catch (error) {
    console.error('Error fetching new opportunities:', error);
    return [];
  }
}

async function clearIndexedDB() {
  const db = await openDB();
  const transaction = db.transaction(['pipelines', 'users', 'opportunities'], 'readwrite');
  transaction.objectStore('pipelines').clear();
  transaction.objectStore('users').clear();
  transaction.objectStore('opportunities').clear();
  return new Promise((resolve) => { transaction.oncomplete = resolve; });
}

function formatDateTimeWithOffset(utcDateString) {
  if (!utcDateString) return 'N/A';
  const date = new Date(utcDateString);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}:${String(date.getSeconds()).padStart(2, '0')}`;
}

function applyFilters(opportunities, pipelineId, startDate, endDate, hiddenProjects = new Set(), additionalFilters = {}) {
  let filtered = opportunities;
  if (pipelineId) filtered = filtered.filter(op => op.pipelineId === pipelineId);
  if (startDate) {
    const start = new Date(startDate.includes(' ') ? startDate : `${startDate} 00:00:00`);
    filtered = filtered.filter(op => new Date(op.createdAt) >= start);
  }
  if (endDate) {
    const end = new Date(endDate.includes(' ') ? endDate : `${endDate} 23:59:59.999`);
    filtered = filtered.filter(op => new Date(op.createdAt) <= end);
  }
  if (additionalFilters.project) filtered = filtered.filter(op => op.project === additionalFilters.project);
  if (additionalFilters.sourceCategory) filtered = filtered.filter(op => op.sourceCategory === additionalFilters.sourceCategory);
  if (additionalFilters.channels?.length > 0 && !additionalFilters.channels.includes('all')) {
    filtered = filtered.filter(op => op.source && additionalFilters.channels.includes(op.source));
  }
  return filtered;
}

export {
  openDB, getCache, setCache, getAllByIndex, clearOpportunitiesCache, 
  getLocationId, loadConfig, fetchPipelinesV2, fetchAllOpportunities, 
  formatDateTimeWithOffset, applyFilters, clearIndexedDB, progressManager,
  fetchNewOpportunities, fetchAllUsers
};