const GHLDATA_VERSION = '1.0.0';
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
    
    const boundedPercentage = Math.min(Math.max(percentage, 0), 100);
    this.progressBar.style.width = boundedPercentage + '%';
    this.progressBar.setAttribute('aria-valuenow', boundedPercentage);
    this.percentageText.textContent = boundedPercentage + '%';

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

async function getCache(storeName, key) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const transaction = db.transaction(storeName, 'readonly');
    const store = transaction.objectStore(storeName);
    const request = store.get(storeName === 'pipelines' ? key : key);
    request.onsuccess = () => resolve(request.result?.data || null);
    request.onerror = () => reject(request.error);
  });
}

async function setCache(storeName, key, data) {
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
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const transaction = db.transaction(storeName, 'readonly');
    const store = transaction.objectStore(storeName);
    const index = store.index(indexName);
    const request = index.getAll(IDBKeyRange.only(indexValue));
    request.onsuccess = () => resolve(request.result.map(item => item.data));
    request.onerror = () => reject(request.error);
  });
}

async function getLatestOpportunityId(pipelineId, locationId) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const transaction = db.transaction('opportunities', 'readonly');
    const store = transaction.objectStore('opportunities');
    const index = store.index('pipelineId');
    const request = index.openCursor(IDBKeyRange.only(pipelineId), 'prev');
    request.onsuccess = () => {
      const cursor = request.result;
      resolve(cursor ? cursor.value.id : null);
    };
    request.onerror = () => reject(request.error);
  });
}

async function clearOpportunitiesCache(locationId, pipelineId) {
  console.log(`Clearing opportunities cache for locationId: ${locationId}, pipelineId: ${pipelineId}`);
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const transaction = db.transaction('opportunities', 'readwrite');
    const store = transaction.objectStore('opportunities');
    const index = store.index('pipelineId');
    const request = index.openCursor(IDBKeyRange.only(pipelineId));
    
    request.onsuccess = (event) => {
      const cursor = event.target.result;
      if (cursor) {
        cursor.delete();
        cursor.continue();
      }
    };
    
    transaction.oncomplete = () => {
      console.log(`Successfully cleared opportunities cache for pipelineId: ${pipelineId}`);
      resolve();
    };
    transaction.onerror = () => {
      console.error('Error clearing opportunities cache:', transaction.error);
      reject(transaction.error);
    };
  });
}

function getLocationId() {
  const urlParams = new URLSearchParams(window.location.search);
  let locId = urlParams.get('locationId');
  if (locId) return locId;
  try {
    const parentUrl = window.parent.location.href;
    const match = parentUrl.match(/\/location\/([^/]+)\//);
    return match && match[1] ? match[1] : null;
  } catch (e) {
    console.error('Error accessing parent URL:', e);
    return null;
  }
}

function selectConfig(configs, locId) {
  return configs.find(c => c.defaultLocationId === locId) || {};
}

async function loadConfig() {
  try {
    const cacheBuster = `?v=${Date.now()}`;
    const response = await fetch(`../config.json${cacheBuster}`, { 
      cache: 'no-store',
      headers: {
        'Cache-Control': 'no-cache, no-store, must-revalidate',
        'Pragma': 'no-cache',
        'Expires': '0'
      }
    });
    if (!response.ok) throw new Error(`Failed to load config: ${response.status}`);
    const configs = await response.json();
    const locationId = getLocationId();
    console.log('Detected locationId:', locationId);
    const config = selectConfig(configs, locationId);
    console.log('Selected CONFIG:', config);
    if (!Object.keys(config).length) {
      console.error('No configuration found for locationId:', locationId);
      return { config: null, locationId };
    }
    return { config, locationId };
  } catch (error) {
    console.error('Error loading config:', error);
    return { config: null, locationId: null };
  }
}

async function fetchPipelines(config, locationId) {
  const cachedPipelines = await getCache('pipelines', locationId);
  if (cachedPipelines) return cachedPipelines;

  try {
    const response = await fetch('https://rest.gohighlevel.com/v1/pipelines/', {
      headers: { 'Authorization': `Bearer ${config.apiKey}` }
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

async function fetchPipelinesV2(config, locationId) {
  const cachedPipelines = await getCache('pipelines', locationId);
  if (cachedPipelines) return cachedPipelines;

  try {
    const response = await fetch(`https://services.leadconnectorhq.com/opportunities/pipelines?locationId=${locationId}`, {
      headers: { 
        'Authorization': `Bearer ${config.accessToken}` ,
        'Version': '2021-07-28'
      }
    });
    if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);
    const data = await response.json();
    console.log(`[Pipelines] Raw API response received:`, data); // Full response (includes traceId etc.)
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
    console.log('Cached users:', cachedUsers);
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
      console.log(`Storing user: id=${user.id}, name=${name}, email=${user.email}`);
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
    console.log('Fetched and stored users:', users);
    return users;
  } catch (error) {
    console.error('Error fetching users:', error);
    return [];
  }
}

async function fetchAllOpportunities(config, locationId, pipelineId, forceRefresh = false, showProgress = false) {
  if (!forceRefresh) {
    const cachedOpportunities = await getAllByIndex('opportunities', 'pipelineId', pipelineId);
    if (cachedOpportunities.length > 0) {
      console.log(`Using cached opportunities for pipelineId: ${pipelineId}, count: ${cachedOpportunities.length}`);
      if (showProgress) {
        progressManager.setProgress(100, 'Data loaded from cache');
        await new Promise(resolve => setTimeout(resolve, 500));
        progressManager.hide();
      }
      return cachedOpportunities;
    }
  } else {
    await clearOpportunitiesCache(locationId, pipelineId);
  }

  if (showProgress) {
    progressManager.show('Fetching opportunities data from server...');
  }

  let allOpportunities = [];
  let startAfter = null;
  let startAfterId = null;
  let hasMore = true;
  const limit = 100;
  let totalFetched = 0;

  try {
    console.log(`Starting to fetch opportunities for locationId: ${locationId}, pipelineId: ${pipelineId}`);
    while (hasMore) {
      let url = `https://services.leadconnectorhq.com/opportunities/search?location_id=${locationId}&limit=${limit}`;
      if (startAfter && startAfterId) url += `&startAfter=${startAfter}&startAfterId=${startAfterId}`;
      else if (startAfterId) url += `&startAfterId=${startAfterId}`;

      if (showProgress) {
        progressManager.setProgress(20 + (totalFetched / 100), `Fetching batch ${Math.floor(totalFetched / limit) + 1}...`);
      }

      console.log(`Fetching batch from URL: ${url}`);
      const response = await fetch(url, {
        headers: { 'Authorization': `Bearer ${config.accessToken}`, 'Version': '2021-07-28' }
      });
      if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);
      const data = await response.json();

      const batchOpportunities = data.opportunities || [];
      console.log(`Retrieved ${batchOpportunities.length} opportunities in this batch`);
      allOpportunities = allOpportunities.concat(batchOpportunities);
      totalFetched += batchOpportunities.length;
      console.log(`Cumulative total opportunities fetched: ${totalFetched}`);

      startAfter = data.meta?.startAfter || null;
      startAfterId = data.meta?.startAfterId || null;
      hasMore = !!startAfter && !!startAfterId && allOpportunities.length < (data.meta?.total || 0);
    }

    if (showProgress) {
      progressManager.setProgress(70, 'Processing opportunities...');
    }

    if (allOpportunities.length === 0) {
      console.log('No opportunities retrieved from API');
      if (showProgress) {
        progressManager.setProgress(100, 'No opportunities found');
        await new Promise(resolve => setTimeout(resolve, 500));
        progressManager.hide();
      }
      return [];
    }

    const processedOpportunities = allOpportunities.map(op => {
      const customFields = op.customFields || [];
      const sourceCategory = customFields.find(cf => cf.id === config.customFieldIds?.sourceCategory)?.fieldValueString || 'N/A';
      const project = customFields.find(cf => cf.id === config.customFieldIds?.project)?.fieldValueString || 'N/A';
      const team = customFields.find(cf => cf.id === config.customFieldIds?.team)?.fieldValueString || 'N/A';
      console.log(`Processing opportunity ${op.id}: sourceCategory=${sourceCategory}, project=${project}, team=${team}`);
      return {
        id: op.id,
        pipelineId: op.pipelineId,
        pipelineStageId: op.pipelineStageId,
        name: op.name,
        assignedTo: op.assignedTo,
        status: op.status,
        source: op.source,
        createdAt: op.createdAt,
        sourceCategory,
        project,
        team,
        contact: op.contact || { name: 'N/A', phone: 'N/A' },
        monetaryValue: op.monetaryValue || 0
      };
    });

    if (showProgress) {
      progressManager.setProgress(85, 'Saving to cache...');
    }

    const db = await openDB();
    const transaction = db.transaction('opportunities', 'readwrite');
    const store = transaction.objectStore('opportunities');
    console.log(`Adding ${processedOpportunities.length} processed opportunities to IndexedDB`);
    processedOpportunities.forEach(op => {
      store.put({
        locationId,
        pipelineId: op.pipelineId,
        id: op.id,
        data: op
      });
    });
    
    await new Promise((resolve, reject) => {
      transaction.oncomplete = () => {
        console.log(`Successfully added ${processedOpportunities.length} opportunities to IndexedDB for pipelineId: ${pipelineId}`);
        resolve();
      };
      transaction.onerror = () => {
        console.error('Error adding opportunities to IndexedDB:', transaction.error);
        reject(transaction.error);
      };
    });

    if (showProgress) {
      progressManager.setProgress(100, 'Syncing to other widgets...');
      localStorage.setItem('ghl_last_refresh', Date.now().toString());
      await new Promise(resolve => setTimeout(resolve, 800));
      progressManager.hide();
    }

    return processedOpportunities;
  } catch (error) {
    console.error('Error fetching opportunities:', error);
    if (showProgress) {
      progressManager.setProgress(100, 'Error fetching data. Please try again.');
      await new Promise(resolve => setTimeout(resolve, 1500));
      progressManager.hide();
    }
    return [];
  }
}

async function fetchNewOpportunities(config, locationId, pipelineId) {
  try {
    // Get all cached opportunities for this pipeline
    const cachedOpps = await getAllByIndex('opportunities', 'pipelineId', pipelineId);
    const cachedIds = new Set(cachedOpps.map(op => op.id));
    console.log(`Cached opportunities count: ${cachedIds.size}`);

    let newOpportunities = [];
    let startAfter = null;
    let startAfterId = null;
    let hasMore = true;
    const limit = 100;
    let batchCount = 0;

    // Fetch from the beginning to find new opportunities
    while (hasMore && batchCount < 100) { // Safety limit of 100 batches
      batchCount++;
      let url = `https://services.leadconnectorhq.com/opportunities/search?location_id=${locationId}&limit=${limit}`;
      if (startAfter && startAfterId) url += `&startAfter=${startAfter}&startAfterId=${startAfterId}`;

      console.log(`Background refresh: Fetching batch ${batchCount}...`);
      const response = await fetch(url, {
        headers: { 'Authorization': `Bearer ${config.accessToken}`, 'Version': '2021-07-28' }
      });
      if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);
      const data = await response.json();

      const batchOpps = data.opportunities || [];
      
      // Only keep opportunities that are NOT already cached
      const trulyNewOpps = batchOpps.filter(op => !cachedIds.has(op.id));
      
      if (trulyNewOpps.length > 0) {
        console.log(`Batch ${batchCount}: Found ${trulyNewOpps.length} new opportunities (skipped ${batchOpps.length - trulyNewOpps.length} existing)`);
        newOpportunities = newOpportunities.concat(trulyNewOpps);
      } else {
        console.log(`Batch ${batchCount}: No new opportunities found, stopping`);
        break; // Stop if this batch has no new opportunities
      }

      startAfter = data.meta?.startAfter || null;
      startAfterId = data.meta?.startAfterId || null;
      hasMore = !!startAfter && !!startAfterId;
    }

    if (newOpportunities.length === 0) {
      console.log('Background refresh: No new opportunities found');
      return [];
    }

    console.log(`Background refresh: Processing ${newOpportunities.length} new opportunities`);

    const processedOpportunities = newOpportunities.map(op => {
      const customFields = op.customFields || [];
      const sourceCategory = customFields.find(cf => cf.id === config.customFieldIds?.sourceCategory)?.fieldValueString || 'N/A';
      const project = customFields.find(cf => cf.id === config.customFieldIds?.project)?.fieldValueString || 'N/A';
      const team = customFields.find(cf => cf.id === config.customFieldIds?.team)?.fieldValueString || 'N/A';
      return {
        id: op.id,
        pipelineId: op.pipelineId,
        pipelineStageId: op.pipelineStageId,
        name: op.name,
        assignedTo: op.assignedTo,
        status: op.status,
        source: op.source,
        createdAt: op.createdAt,
        sourceCategory,
        project,
        team,
        contact: op.contact || { name: 'N/A', phone: 'N/A' },
        monetaryValue: op.monetaryValue || 0
      };
    });

    // Only add NEW opportunities to IndexedDB
    const db = await openDB();
    const transaction = db.transaction('opportunities', 'readwrite');
    const store = transaction.objectStore('opportunities');
    processedOpportunities.forEach(op => {
      store.put({
        locationId,
        pipelineId: op.pipelineId,
        id: op.id,
        data: op
      });
    });
    
    await new Promise((resolve, reject) => {
      transaction.oncomplete = () => {
        console.log(`Background refresh: Successfully added ${processedOpportunities.length} new opportunities to IndexedDB`);
        resolve();
      };
      transaction.onerror = () => {
        console.error('Error adding new opportunities to IndexedDB:', transaction.error);
        reject(transaction.error);
      };
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
  
  const pipelinesStore = transaction.objectStore('pipelines');
  const usersStore = transaction.objectStore('users');
  const opportunitiesStore = transaction.objectStore('opportunities');
  
  await Promise.all([
    new Promise((resolve, reject) => {
      const request = pipelinesStore.clear();
      request.onsuccess = resolve;
      request.onerror = reject;
    }),
    new Promise((resolve, reject) => {
      const request = usersStore.clear();
      request.onsuccess = resolve;
      request.onerror = reject;
    }),
    new Promise((resolve, reject) => {
      const request = opportunitiesStore.clear();
      request.onsuccess = resolve;
      request.onerror = reject;
    })
  ]);
}

function formatDateTimeWithOffset(utcDateString) {
  if (!utcDateString) return 'N/A';
  const date = new Date(utcDateString);
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  const hours = String(date.getHours()).padStart(2, '0');
  const minutes = String(date.getMinutes()).padStart(2, '0');
  const seconds = String(date.getSeconds()).padStart(2, '0');
  return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}

function applyFilters(opportunities, pipelineId, startDate, endDate, hiddenProjects = new Set(), additionalFilters = {}) {
  let filtered = opportunities;
  console.log('Applying filters:', { pipelineId, startDate, endDate, hiddenProjects, additionalFilters });

  if (pipelineId) {
    filtered = filtered.filter(op => op.pipelineId === pipelineId);
    console.log(`Filtered by pipelineId ${pipelineId}: ${filtered.length} opportunities`);
  }

  if (startDate) {
    const start = new Date(startDate.includes(' ') ? startDate : `${startDate} 00:00:00`);
    start.setHours(0, 0, 0, 0);
    console.log('Filter start date (local):', start.toString(), 'ISO:', start.toISOString());
    filtered = filtered.filter(op => {
      const createdAtFormatted = formatDateTimeWithOffset(op.createdAt);
      const createdAtDate = new Date(createdAtFormatted);
      return createdAtDate >= start;
    });
    console.log(`Filtered by startDate ${startDate}: ${filtered.length} opportunities`);
  }

  if (endDate) {
    const end = new Date(endDate.includes(' ') ? endDate : `${endDate} 23:59:59.999`);
    end.setHours(23, 59, 59, 999);
    console.log('Filter end date (local):', end.toString(), 'ISO:', end.toISOString());
    filtered = filtered.filter(op => {
      const createdAtFormatted = formatDateTimeWithOffset(op.createdAt);
      const createdAtDate = new Date(createdAtFormatted);
      return createdAtDate <= end;
    });
    console.log(`Filtered by endDate ${endDate}: ${filtered.length} opportunities`);
  }

  if (hiddenProjects.size > 0) {
    filtered = filtered.filter(op => !hiddenProjects.has(op.project));
    console.log(`Filtered by hiddenProjects: ${filtered.length} opportunities`);
  }

  if (additionalFilters.project) {
    filtered = filtered.filter(op => op.project === additionalFilters.project);
    console.log(`Filtered by project ${additionalFilters.project}: ${filtered.length} opportunities`);
  }

  if (additionalFilters.type && additionalFilters.type !== 'all') {
    filtered = filtered.filter(op => {
      const catLower = op.sourceCategory ? op.sourceCategory.toLowerCase() : '';
      return additionalFilters.type === 'online' ? catLower.includes('online') : catLower.includes('offline');
    });
    console.log(`Filtered by type ${additionalFilters.type}: ${filtered.length} opportunities`);
  }

  if (additionalFilters.sourceCategory) {
    filtered = filtered.filter(op => op.sourceCategory === additionalFilters.sourceCategory);
    console.log(`Filtered by sourceCategory ${additionalFilters.sourceCategory}: ${filtered.length} opportunities`);
  }

  if (additionalFilters.channels && additionalFilters.channels.length > 0 && !additionalFilters.channels.includes('all')) {
    filtered = filtered.filter(op => op.source && additionalFilters.channels.includes(op.source));
    console.log(`Filtered by channels ${additionalFilters.channels}: ${filtered.length} opportunities`);
  }

  if (additionalFilters.sourceCategories && additionalFilters.sourceCategories.length > 0 && !additionalFilters.sourceCategories.includes('all')) {
    filtered = filtered.filter(op => op.sourceCategory && additionalFilters.sourceCategories.includes(op.sourceCategory));
    console.log(`Filtered by sourceCategories ${additionalFilters.sourceCategories}: ${filtered.length} opportunities`);
  }

  if (additionalFilters.agents && additionalFilters.agents.length > 0 && !additionalFilters.agents.includes('all')) {
    filtered = filtered.filter(op => op.assignedTo && additionalFilters.agents.includes(op.assignedTo));
    console.log(`Filtered by agents ${additionalFilters.agents}: ${filtered.length} opportunities`);
  }

  if (additionalFilters.teams && additionalFilters.teams.length > 0 && !additionalFilters.teams.includes('all')) {
    filtered = filtered.filter(op => op.team && additionalFilters.teams.includes(op.team));
    console.log(`Filtered by teams ${additionalFilters.teams}: ${filtered.length} opportunities`);
  }

  if (additionalFilters.stageId) {
    filtered = filtered.filter(op => op.pipelineStageId === additionalFilters.stageId);
    console.log(`Filtered by stageId ${additionalFilters.stageId}: ${filtered.length} opportunities`);
  }
  
  console.log(`Final filtered opportunities: ${filtered.length}`);
  return filtered;
}

export {
  openDB,
  getCache,
  setCache,
  getAllByIndex,
  getLatestOpportunityId,
  clearOpportunitiesCache,
  getLocationId,
  selectConfig,
  loadConfig,
  fetchPipelinesV2,
  fetchPipelinesV2,
  fetchAllUsers,
  fetchAllOpportunities,
  fetchNewOpportunities,
  formatDateTimeWithOffset,
  applyFilters,
  clearIndexedDB,
  progressManager
};