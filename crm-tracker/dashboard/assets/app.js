const tabs = {
  appointments: {
    endpoint: 'api/appointments.php',
    label: 'Appointments & Bookings',
    render: renderAppointments,
  },
  marketing: {
    endpoint: 'api/marketing.php',
    label: 'Marketing Tools',
    render: renderMarketing,
  },
  social: {
    endpoint: 'api/social.php',
    label: 'Social Media',
    render: renderSocial,
  },
  pipeline: {
    label: 'Pipeline',
    render: renderPipeline,
  },
};

let activeTab = 'appointments';
let selectedLocationId = null;
let locations = [];
let socialRange = {
  startDate: '2020-01-01',
  endDate: todayDate(),
};

const tabCache = new Map();
const content = document.querySelector('#content');
const statusEl = document.querySelector('#status');
const refreshButton = document.querySelector('#refreshButton');
const locationSelect = document.querySelector('#locationSelect');
const pageTitle = document.querySelector('#pageTitle');
const pageEyebrow = document.querySelector('#pageEyebrow');

document.addEventListener('DOMContentLoaded', bootstrap);

function bootstrap() {
  document.querySelectorAll('.tab').forEach((button) => {
    button.addEventListener('click', () => activateTab(button.dataset.tab));
  });

  refreshButton.addEventListener('click', () => fetchActiveTab(true));
  locationSelect.addEventListener('change', () => {
    selectedLocationId = locationSelect.value;
    fetchActiveTab(false);
  });

  loadLocations();
}

async function loadLocations() {
  setStatus('Loading subaccounts...');
  refreshButton.disabled = true;

  try {
    const response = await fetch('api/locations.php', {
      headers: { Accept: 'application/json' },
    });
    const payload = await response.json();

    if (!response.ok) {
      throw new Error(payload.error || `Request failed with HTTP ${response.status}`);
    }

    locations = Array.isArray(payload.locations) ? payload.locations : [];
    if (locations.length === 0) {
      locationSelect.innerHTML = '<option>No subaccounts configured</option>';
      setStatus('No subaccounts configured.');
      content.innerHTML = '<div class="notice">Add locations to config.json to load dashboard data.</div>';
      return;
    }

    selectedLocationId = payload.default || locations[0].id;
    populateLocationSelect();
    activateTab(activeTab);
  } catch (error) {
    setStatus(error.message, true);
    content.innerHTML = `<div class="notice is-error">${escapeHtml(error.message)}</div>`;
  } finally {
    refreshButton.disabled = false;
  }
}

function populateLocationSelect() {
  locationSelect.innerHTML = locations.map((location) => (
    `<option value="${escapeHtml(location.id)}">${escapeHtml(location.name)}</option>`
  )).join('');
  locationSelect.value = selectedLocationId;
  locationSelect.disabled = false;
}

function activateTab(tabName) {
  activeTab = tabName;
  document.querySelectorAll('.tab').forEach((button) => {
    button.classList.toggle('is-active', button.dataset.tab === tabName);
  });
  pageTitle.textContent = tabs[tabName].label;
  pageEyebrow.textContent = tabName === 'social' ? 'Social analytics' : 'Dashboard';

  if (!selectedLocationId) {
    return;
  }

  fetchActiveTab(false);
}

function fetchActiveTab(forceRefresh) {
  if (activeTab === 'pipeline') {
    renderPipelineTab();
    return Promise.resolve();
  }

  if (activeTab === 'social') {
    return fetchSocial(selectedLocationId, socialRange.startDate, socialRange.endDate, forceRefresh);
  }

  return fetchTab(activeTab, selectedLocationId, forceRefresh);
}

function cacheKey(locationId, tab) {
  return `${locationId}::${tab}`;
}

function socialCacheKey(locationId, startDate, endDate) {
  return `${locationId}::social::${startDate}::${endDate}`;
}

async function fetchTab(tabName, locationId, forceRefresh) {
  const tab = tabs[tabName];
  const key = cacheKey(locationId, tabName);

  if (tabCache.has(key) && !forceRefresh) {
    renderPayload(tabName, tabCache.get(key));
    setStatus(`${tab.label} loaded from this page session.`);
    return;
  }

  const params = new URLSearchParams({ locationId });
  if (forceRefresh) {
    params.set('refresh', '1');
  }

  await fetchAndRender(tabName, `${tab.endpoint}?${params}`, key);
}

async function fetchSocial(locationId, startDate, endDate, forceRefresh) {
  const key = socialCacheKey(locationId, startDate, endDate);

  if (tabCache.has(key) && !forceRefresh) {
    renderPayload('social', tabCache.get(key));
    setStatus('Social Media loaded from this page session.');
    return;
  }

  const params = new URLSearchParams({ locationId, startDate, endDate });
  if (forceRefresh) {
    params.set('refresh', '1');
  }

  await fetchAndRender('social', `${tabs.social.endpoint}?${params}`, key);
}

async function fetchAndRender(tabName, url, key) {
  const tab = tabs[tabName];

  setStatus(`Loading ${tab.label}...`);
  content.classList.remove('is-pipeline');
  content.innerHTML = tabName === 'social' ? renderSocialShell(true) : '';
  refreshButton.disabled = true;
  setSocialLoading(true);

  try {
    const response = await fetch(url, {
      headers: { Accept: 'application/json' },
    });
    const payload = await response.json();

    if (!response.ok) {
      throw new Error(payload.error || `Request failed with HTTP ${response.status}`);
    }

    tabCache.set(key, payload);
    renderPayload(tabName, payload);
    setStatus(`${tab.label} updated.`);
  } catch (error) {
    setStatus(error.message, true);
    content.innerHTML = tabName === 'social'
      ? renderSocialShell(false, error.message)
      : `<div class="notice is-error">${escapeHtml(error.message)}</div>`;
    if (tabName === 'social') {
      requestAnimationFrame(bindSocialControls);
    }
  } finally {
    refreshButton.disabled = false;
    setSocialLoading(false);
  }
}

function renderPayload(tabName, payload) {
  content.classList.remove('is-pipeline');
  const locationsPayload = Array.isArray(payload.locations) ? payload.locations : [];
  const errors = Array.isArray(payload.errors) ? payload.errors : [];
  const notices = [];

  if (errors.length > 0) {
    notices.push(
      `<div class="notice is-error">${errors.map((error) => escapeHtml(error.message || 'A location failed to load.')).join('<br>')}</div>`
    );
  }

  if (locationsPayload.length === 0) {
    notices.push('<div class="notice">No data returned for this subaccount.</div>');
  }

  content.innerHTML = notices.join('') + tabs[tabName].render(locationsPayload);
}

function renderPipelineTab() {
  content.classList.add('is-pipeline');
  statusEl.textContent = '';
  statusEl.classList.remove('is-error');
  content.innerHTML = renderPipeline(selectedLocationId);
}

function renderAppointments(locationsPayload) {
  return locationsPayload.map((location) => {
    const appointmentRows = new Map();
    (location.appointments?.byUser || []).forEach((row) => {
      appointmentRows.set(row.id, { id: row.id, name: row.name, appointments: row.total, bookings: 0 });
    });
    (location.bookings?.byUser || []).forEach((row) => {
      const existing = appointmentRows.get(row.id) || { id: row.id, name: row.name, appointments: 0, bookings: 0 };
      existing.bookings = row.total;
      appointmentRows.set(row.id, existing);
    });

    const rows = [...appointmentRows.values()].sort((a, b) => (b.appointments + b.bookings) - (a.appointments + a.bookings));
    const body = rows.map((row) => `
      <tr>
        <td>${escapeHtml(row.name)}</td>
        <td class="number">${formatNumber(row.appointments)}</td>
        <td class="number">${formatNumber(row.bookings)}</td>
      </tr>
    `).join('');

    const stageNotice = location.bookings?.stageFound === false
      ? '<div class="notice is-warning">No Booking stage found for this subaccount.</div>'
      : '';

    return `
      <article class="panel">
        <div class="panel-header">
          <div>
            <h2>${escapeHtml(location.name)}</h2>
            <p class="muted">${escapeHtml(location.id)}</p>
          </div>
          <div class="metric-row">
            ${metric('Appointments', location.appointments?.total || 0)}
            ${metric('Bookings', location.bookings?.total || 0)}
          </div>
        </div>
        ${stageNotice}
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>User</th>
                <th class="number">Appointments</th>
                <th class="number">Bookings</th>
              </tr>
            </thead>
            <tbody>${body || '<tr><td colspan="3">No users or activity found.</td></tr>'}</tbody>
            <tfoot>
              <tr>
                <td>Total</td>
                <td class="number">${formatNumber(location.appointments?.total || 0)}</td>
                <td class="number">${formatNumber(location.bookings?.total || 0)}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </article>
    `;
  }).join('');
}

function renderMarketing(locationsPayload) {
  return `<div class="grid">${locationsPayload.map((location) => `
    <article class="card">
      <div class="card-header">
        <div>
          <h2>${escapeHtml(location.name)}</h2>
          <p class="muted">${escapeHtml(location.id)}</p>
        </div>
      </div>
      <div class="card-body">
        <div class="marketing-split">
          <section class="marketing-column">
            ${metric('Forms', location.forms?.total || 0)}
            ${renderList(location.forms?.items || [])}
          </section>
          <section class="marketing-column">
            ${metric('Surveys', location.surveys?.total || 0)}
            ${renderList(location.surveys?.items || [])}
          </section>
        </div>
      </div>
    </article>
  `).join('')}</div>`;
}

function renderSocial(locationsPayload) {
  const sections = locationsPayload.map((location) => {
    const accounts = location.accounts || [];
    const results = location.results;
    const totals = results?.totals || {};
    const postsByPlatform = breakdownPlatformValues(results, 'posts');
    const maxPosts = Math.max(...Object.values(postsByPlatform), 1);
    const platformRows = Object.entries(postsByPlatform).map(([platform, total]) => {
      const width = Math.max(2, Math.round((total / maxPosts) * 100));
      return `
        <div class="bar-row">
          <span>${escapeHtml(displayPlatform(platform))}</span>
          <span class="bar-track"><span class="bar-fill" style="width: ${width}%"></span></span>
          <strong>${formatNumber(total)} posts</strong>
        </div>
      `;
    }).join('');

    return `
      <section class="social-layout">
        <div>
          <p class="section-label">Overview</p>
          <div class="kpi-grid">
            ${metric('Total posts', totals.posts || 0)}
          </div>
        </div>

        <div class="charts-row">
          <article class="chart-card">
            <div class="chart-header">
              <h2 class="chart-title">Posts by platform</h2>
              <span class="muted">${escapeHtml(location.name)}</span>
            </div>
            <div class="bar-list">${platformRows || '<div class="notice">No posts found for this range.</div>'}</div>
          </article>

          <article>
            <div class="section-header">
              <p class="section-label">Connected accounts</p>
            </div>
            ${renderConnectedAccounts(accounts)}
          </article>
        </div>
      </section>
    `;
  }).join('');

  return renderSocialShell(false) + sections;
}

function renderConnectedAccounts(accounts) {
  if (!accounts.length) {
    return '<div class="platform-list"><div class="platform-empty">No accounts connected</div></div>';
  }

  return `
    <div class="platform-list">
      ${accounts.map((account) => {
        const platform = platformKey(account.platform);
        return `
          <div class="platform-row">
            <span class="platform-icon ${platform}" aria-hidden="true">${platformGlyph(platform)}</span>
            <span class="platform-name">${escapeHtml(account.name || displayPlatform(account.platform))}</span>
          </div>
        `;
      }).join('')}
    </div>
  `;
}

function breakdownPlatformValues(results, metricName) {
  const platforms = results?.breakdowns?.[metricName]?.platforms || {};
  return Object.fromEntries(Object.entries(platforms).map(([platform, data]) => [
    platform,
    Number(data?.value || 0),
  ]));
}

function renderSocialShell(loading = false, error = '') {
  return `
    <section class="date-panel">
      <p class="section-label">Date range</p>
      <div class="date-controls">
        <label>
          <span>From</span>
          <input id="socialStartDate" type="date" value="${escapeHtml(socialRange.startDate)}" max="${escapeHtml(socialRange.endDate)}">
        </label>
        <label>
          <span>To</span>
          <input id="socialEndDate" type="date" value="${escapeHtml(socialRange.endDate)}" max="${todayDate()}">
        </label>
        <button id="socialApplyButton" type="button" ${loading ? 'disabled' : ''}>${loading ? 'Loading...' : 'Apply'}</button>
      </div>
      <p class="inline-error" id="socialDateError">${escapeHtml(error)}</p>
    </section>
  `;
}

function bindSocialControls() {
  const startInput = document.querySelector('#socialStartDate');
  const endInput = document.querySelector('#socialEndDate');
  const applyButton = document.querySelector('#socialApplyButton');
  const errorEl = document.querySelector('#socialDateError');

  if (!startInput || !endInput || !applyButton || !errorEl) {
    return;
  }

  const syncLimits = () => {
    startInput.max = endInput.value || todayDate();
    endInput.min = startInput.value || '2020-01-01';
  };

  startInput.addEventListener('change', syncLimits);
  endInput.addEventListener('change', syncLimits);
  applyButton.addEventListener('click', () => {
    const startDate = startInput.value;
    const endDate = endInput.value;

    if (!startDate || !endDate) {
      errorEl.textContent = 'Choose both start and end dates.';
      return;
    }

    if (startDate > endDate) {
      errorEl.textContent = 'Start date must be before or equal to end date.';
      return;
    }

    socialRange = { startDate, endDate };
    errorEl.textContent = '';
    fetchSocial(selectedLocationId, startDate, endDate, true);
  });
  syncLimits();
}

function renderPipeline(locationId) {
  if (!locationId) {
    return '<section class="tab-panel--pipeline"><p class="pipeline-empty">No location selected.</p></section>';
  }

  const pipelineUrl = `https://widget.salesjourney360.com/widget/funnel/funnel_one_chart.html?locationId=${encodeURIComponent(locationId)}`;
  return `
    <section class="tab-panel--pipeline">
      <iframe
        src="${pipelineUrl}"
        title="Pipeline Chart"
        width="100%"
        height="100%"
        frameborder="0"
        allowtransparency="true"
        loading="lazy"
      ></iframe>
    </section>
  `;
}

function renderList(items) {
  if (!items.length) {
    return '<div class="notice">No items found.</div>';
  }

  return `<ul class="list">${items.map((item) => `
    <li>
      <span>${escapeHtml(item.name)}</span>
      <span class="muted">${escapeHtml(item.id)}</span>
    </li>
  `).join('')}</ul>`;
}

function metric(label, value) {
  const isEmpty = Number(value || 0) === 0;
  return `<div class="metric"><span>${escapeHtml(label)}</span><strong class="${isEmpty ? 'is-empty' : ''}">${isEmpty ? '&mdash;' : formatNumber(value)}</strong></div>`;
}

function setSocialLoading(loading) {
  const applyButton = document.querySelector('#socialApplyButton');
  if (!applyButton) {
    return;
  }

  applyButton.disabled = loading;
  applyButton.textContent = loading ? 'Loading...' : 'Apply';
}

function setStatus(message, isError = false) {
  statusEl.textContent = message;
  statusEl.classList.toggle('is-error', isError);
  if (activeTab === 'social') {
    requestAnimationFrame(bindSocialControls);
  }
}

function formatNumber(value) {
  return new Intl.NumberFormat().format(Number(value || 0));
}

function displayPlatform(platform) {
  const text = String(platform || 'unknown').replace(/[_-]+/g, ' ');
  return text.charAt(0).toUpperCase() + text.slice(1);
}

function platformKey(platform) {
  return String(platform || 'unknown').toLowerCase().replace(/[^a-z0-9]+/g, '-');
}

function platformGlyph(platform) {
  const glyphs = {
    facebook: 'f',
    instagram: 'ig',
    linkedin: 'in',
    twitter: 'x',
    x: 'x',
    youtube: 'yt',
  };

  return glyphs[platform] || displayPlatform(platform).charAt(0);
}

function todayDate() {
  return new Date().toISOString().slice(0, 10);
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  })[char]);
}
