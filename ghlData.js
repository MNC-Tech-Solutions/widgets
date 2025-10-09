// ghlData.js (updated with new filters in applyFilters)
const DB_NAME = 'ghl_funnel_db';
const DB_VERSION = 2;

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
    const response = await fetch('../config.json', { cache: 'no-store' });
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

async function fetchAllOpportunities(config, locationId, pipelineId) {
  let cachedOpportunities = await getAllByIndex('opportunities', 'pipelineId', pipelineId);
  if (cachedOpportunities.length > 0) {
    console.log(`Loaded ${cachedOpportunities.length} opportunities from IndexedDB cache for pipelineId: ${pipelineId}`);
    return cachedOpportunities;
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

    if (allOpportunities.length === 0) {
      console.log('No opportunities retrieved from API');
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

    return processedOpportunities;
  } catch (error) {
    console.error('Error fetching opportunities:', error);
    return [];
  }
}

async function fetchNewOpportunities(config, locationId, pipelineId) {
  let latestId = await getLatestOpportunityId(pipelineId, locationId);
  let newOpportunities = [];
  let startAfter = null;
  let startAfterId = latestId;
  let hasMore = !!startAfterId;
  const limit = 100;

  try {
    while (hasMore) {
      let url = `https://services.leadconnectorhq.com/opportunities/search?location_id=${locationId}&limit=${limit}`;
      if (startAfter && startAfterId) url += `&startAfter=${startAfter}&startAfterId=${startAfterId}`;
      else if (startAfterId) url += `&startAfterId=${startAfterId}`;

      const response = await fetch(url, {
        headers: { 'Authorization': `Bearer ${config.accessToken}`, 'Version': '2021-07-28' }
      });
      if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);
      const data = await response.json();

      newOpportunities = newOpportunities.concat(data.opportunities || []);
      startAfter = data.meta?.startAfter || null;
      startAfterId = data.meta?.startAfterId || null;
      hasMore = !!startAfter && !!startAfterId && newOpportunities.length < (data.meta?.total || 0);
    }

    if (newOpportunities.length === 0) return [];

    const processedOpportunities = newOpportunities.map(op => {
      const customFields = op.customFields || [];
      const sourceCategory = customFields.find(cf => cf.id === config.customFieldIds?.sourceCategory)?.fieldValueString || 'N/A';
      const project = customFields.find(cf => cf.id === config.customFieldIds?.project)?.fieldValueString || 'N/A';
      const team = customFields.find(cf => cf.id === config.customFieldIds?.team)?.fieldValueString || 'N/A';
      console.log(`Processing new opportunity ${op.id}: sourceCategory=${sourceCategory}, project=${project}, team=${team}`);
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
      transaction.oncomplete = resolve;
      transaction.onerror = () => reject(transaction.error);
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

  if (additionalFilters.projects && additionalFilters.projects.length > 0 && !additionalFilters.projects.includes('all')) {
    filtered = filtered.filter(op => op.project && additionalFilters.projects.includes(op.project));
    console.log(`Filtered by projects ${additionalFilters.projects}: ${filtered.length} opportunities`);
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
  getLocationId,
  selectConfig,
  loadConfig,
  fetchPipelines,
  fetchAllUsers,
  fetchAllOpportunities,
  fetchNewOpportunities,
  formatDateTimeWithOffset,
  applyFilters,
  clearIndexedDB
};