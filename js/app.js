window.ReportingEngine = {
    state: {
        activeReportId: null,
        reportCategoryId: null,
        definition: {},
        selectedElement: null,
        selectedBand: null,
        selectedBandGroupField: null,
        undoStack: [],
        redoStack: [],
        history: [],
        historyIndex: -1,
        zoom: 1.0,
        isDirty: false,
        queryColumns: [],
    },
    listeners: {},
    dispatch(action, payload) {
        switch (action) {
            case 'LOAD_DEFINITION':
                this.state.definition = payload;
                this.state.isDirty = false;
                break;
            case 'SET_DEFINITION':
                this.state.definition = payload;
                this.state.isDirty = true;
                break;
            case 'SELECT_ELEMENT':
                this.state.selectedElement = payload;
                break;
            case 'SELECT_BAND':
                this.state.selectedBand = payload;
                this.state.selectedBandGroupField = null;
                break;
            case 'SELECT_BAND_GROUP':
                if (payload && typeof payload === 'object') {
                    this.state.selectedBand = payload.type;
                    this.state.selectedBandGroupField = payload.groupField || null;
                } else {
                    this.state.selectedBand = payload;
                    this.state.selectedBandGroupField = null;
                }
                break;
            case 'SET_ZOOM':
                this.state.zoom = payload;
                break;
            case 'SET_DIRTY':
                this.state.isDirty = payload;
                break;
            case 'SET_QUERY_COLUMNS':
                this.state.queryColumns = payload;
                break;
            case 'SET_REPORT_CATEGORY':
                this.state.reportCategoryId = payload;
                break;
            case 'UNDO_STACK':
                this.state.undoStack = payload;
                break;
            case 'REDO_STACK':
                this.state.redoStack = payload;
                break;
        }
        this.emit('stateChange', { action, state: this.state });
    },
    on(event, handler) {
        if (!this.listeners[event]) this.listeners[event] = [];
        this.listeners[event].push(handler);
    },
    emit(event, data) {
        (this.listeners[event] || []).forEach(h => h(data));
    },
    async api(method, url, body = null) {
        const options = {
            method,
            headers: { 'Content-Type': 'application/json' },
        };
        const token = localStorage.getItem('auth_token');
        if (token) options.headers['Authorization'] = 'Bearer ' + token;
        if (body) options.body = JSON.stringify(body);
        const res = await fetch(url, options);
        if (res.status === 401) {
            localStorage.removeItem('auth_token');
            document.cookie = 'auth_token=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
            if (!window.location.pathname.startsWith('/login')) {
                window.location.href = '/login';
            }
            return { success: false, message: 'Authentication required' };
        }
        return res.json();
    }
};

// Auth helpers
function initAuth() {
    const token = localStorage.getItem('auth_token');
    if (!token) {
        const userEl = document.getElementById('navbar-user');
        const logoutEl = document.getElementById('navbar-logout');
        if (userEl) userEl.style.display = 'none';
        if (logoutEl) logoutEl.style.display = 'none';
        return;
    }
    // Decode token payload to show username
    try {
        const parts = token.split('.');
        const payload = JSON.parse(atob(parts[0].replace(/-/g, '+').replace(/_/g, '/')));
        const userEl = document.getElementById('navbar-user');
        const logoutEl = document.getElementById('navbar-logout');
        if (userEl) { userEl.textContent = payload.user; userEl.style.display = 'inline'; }
        if (logoutEl) logoutEl.style.display = 'inline';
    } catch (e) {}
}

function handleLogout() {
    localStorage.removeItem('auth_token');
    document.cookie = 'auth_token=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    window.location.href = '/login';
}

// Global helpers
function toggleConnectionFields() {
    const driver = document.getElementById('conn-driver')?.value;
    const hostFields = document.getElementById('connection-host-fields');
    const authFields = document.getElementById('connection-auth-fields');
    if (hostFields) hostFields.style.display = driver && driver !== 'sqlite' ? 'block' : 'none';
    if (authFields) authFields.style.display = driver && driver !== 'sqlite' ? 'block' : 'none';
}

// Page-specific initialization
document.addEventListener('DOMContentLoaded', () => {
    initAuth();
    const path = window.location.pathname;

    if (path === '/' || path === '/index.php') {
        initDashboard();
    } else if ((path === '/reports' || path === '/reports/') && !path.includes('/designer') && !path.includes('/preview')) {
        initReportsList();
    } else if (path.startsWith('/connections')) {
        if (path === '/connections' || path === '/connections/') {
            initConnectionsList();
        } else if (path.includes('/edit/')) {
            initConnectionEdit();
        }
    }
});

async function initDashboard() {
    try {
        const [reportsRes, connsRes] = await Promise.all([
            window.ReportingEngine.api('GET', '/api/reports'),
            window.ReportingEngine.api('GET', '/api/connections'),
        ]);
        document.querySelector('#stat-reports .stat-value').textContent = reportsRes.data?.length ?? 0;
        document.querySelector('#stat-connections .stat-value').textContent = connsRes.data?.length ?? 0;

        const tbody = document.querySelector('#recent-reports-table tbody');
        if (!reportsRes.data || reportsRes.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-muted">No reports yet</td></tr>';
            return;
        }
        tbody.innerHTML = reportsRes.data.slice(0, 5).map(r => `
            <tr>
                <td><a href="/reports/designer/${r.id}">${escapeHtml(r.name)}</a></td>
                <td>${escapeHtml(r.description || '-')}</td>
                <td>${r.updated_at || '-'}</td>
                <td class="actions">
                    <a class="btn btn-sm" href="/reports/designer/${r.id}"><i class="ph-pencil"></i></a>
                    <a class="btn btn-sm" href="/reports/preview/${r.id}"><i class="ph-eye"></i></a>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        console.error('Dashboard init error:', e);
    }
}

async function initReportsList() {
    try {
        const [reportsRes, catsRes] = await Promise.all([
            window.ReportingEngine.api('GET', '/api/reports'),
            window.ReportingEngine.api('GET', '/api/categories'),
        ]);

        const reports = reportsRes.data || [];
        const categories = catsRes.data || [];

        renderCategoryTabs(categories);
        renderGroupedReports(reports, categories);
    } catch (e) {
        console.error('Reports list error:', e);
        const tbody = document.querySelector('#reports-table tbody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="text-muted">Error loading reports</td></tr>';
    }
}

function renderCategoryTabs(categories) {
    const tabsContainer = document.getElementById('category-tabs');
    if (!tabsContainer) return;

    // "All" tab is already in HTML, remove other children
    const allTab = tabsContainer.querySelector('[data-category-id=""]');
    tabsContainer.innerHTML = '';
    tabsContainer.appendChild(allTab);

    categories.forEach(cat => {
        const tab = document.createElement('button');
        tab.className = 'category-tab';
        tab.dataset.categoryId = cat.id;
        tab.innerHTML = `${escapeHtml(cat.name)} <span class="category-tab-x" onclick="event.stopPropagation();deleteCategory(${cat.id},'${escapeHtml(cat.name)}')">&times;</span>`;
        tab.addEventListener('click', () => filterByCategory(cat.id));
        tabsContainer.appendChild(tab);
    });

    // Update "All" tab click to remove active from others
    allTab.addEventListener('click', () => filterByCategory(''));
}

async function filterByCategory(categoryId) {
    // Update tab active states
    document.querySelectorAll('.category-tab').forEach(t => {
        t.classList.toggle('active', t.dataset.categoryId === String(categoryId));
    });

    const res = await window.ReportingEngine.api('GET', '/api/reports' + (categoryId ? `?category_id=${categoryId}` : ''));
    const reports = res.data || [];

    // Re-fetch categories in case they changed
    const catsRes = await window.ReportingEngine.api('GET', '/api/categories');
    const categories = catsRes.data || [];

    renderGroupedReports(reports, categories);
}

function renderGroupedReports(reports, categories) {
    const container = document.getElementById('reports-container');
    if (!container) return;

    if (reports.length === 0) {
        container.innerHTML = `
            <table class="table">
                <thead><tr><th>Name</th><th>Description</th><th>Category</th><th>Connection</th><th>Updated</th><th>Actions</th></tr></thead>
                <tbody><tr><td colspan="6" class="text-muted">No reports yet. <a href="/reports/new">Create one</a></td></tr></tbody>
            </table>`;
        return;
    }

    // Group reports by category_id
    const grouped = {};
    const uncategorized = [];
    reports.forEach(r => {
        const catId = r.category_id;
        if (catId) {
            if (!grouped[catId]) grouped[catId] = [];
            grouped[catId].push(r);
        } else {
            uncategorized.push(r);
        }
    });

    let html = '';

    // Render category sections in alphabetical order
    const sortedCategories = [...categories].sort((a, b) => a.name.localeCompare(b.name));
    sortedCategories.forEach(cat => {
        const catReports = grouped[cat.id];
        if (!catReports || catReports.length === 0) return;

        html += `
            <div class="category-section">
                <div class="category-header" onclick="toggleCategorySection(this)">
                    <span class="category-caret">&#9654;</span>
                    <span class="category-name">${escapeHtml(cat.name)}</span>
                    <span class="category-count">(${catReports.length})</span>
                </div>
                <div class="category-body">
                    <table class="table">
                        <thead><tr><th>Name</th><th>Description</th><th>Category</th><th>Connection</th><th>Updated</th><th>Actions</th></tr></thead>
                        <tbody>${catReports.map(r => renderReportRow(r, categories)).join('')}</tbody>
                    </table>
                </div>
            </div>`;
    });

    // Render uncategorized section
    if (uncategorized.length > 0) {
        html += `
            <div class="category-section">
                <div class="category-header uncategorized" onclick="toggleCategorySection(this)">
                    <span class="category-caret">&#9654;</span>
                    <span class="category-name">Uncategorized</span>
                    <span class="category-count">(${uncategorized.length})</span>
                </div>
                <div class="category-body">
                    <table class="table">
                        <thead><tr><th>Name</th><th>Description</th><th>Category</th><th>Connection</th><th>Updated</th><th>Actions</th></tr></thead>
                        <tbody>${uncategorized.map(r => renderReportRow(r, categories)).join('')}</tbody>
                    </table>
                </div>
            </div>`;
    }

    container.innerHTML = html;
}

function renderReportRow(r, categories) {
    const catOptions = categories.map(c =>
        `<option value="${c.id}" ${Number(r.category_id) === Number(c.id) ? 'selected' : ''}>${escapeHtml(c.name)}</option>`
    ).join('');

    return `
        <tr>
            <td><a href="/reports/designer/${r.id}">${escapeHtml(r.name)}</a></td>
            <td>${escapeHtml(r.description || '-')}</td>
            <td>
                <select class="category-select-inline" onchange="changeReportCategory(${r.id}, this.value)">
                    <option value="">No Category</option>
                    ${catOptions}
                </select>
            </td>
            <td>${r.connection_name || r.connection_id || '-'}</td>
            <td>${r.updated_at || '-'}</td>
            <td class="actions">
                <a class="btn btn-sm" href="/reports/designer/${r.id}" title="Design"><i class="ph-pencil"></i></a>
                <a class="btn btn-sm" href="/reports/preview/${r.id}" title="Preview"><i class="ph-eye"></i></a>
                <button class="btn btn-sm" onclick="deleteReport(${r.id})" title="Delete"><i class="ph-trash"></i></button>
            </td>
        </tr>`;
}

function toggleCategorySection(headerEl) {
    headerEl.classList.toggle('collapsed');
    const caret = headerEl.querySelector('.category-caret');
    if (caret) caret.innerHTML = headerEl.classList.contains('collapsed') ? '&#9660;' : '&#9654;';
    const body = headerEl.nextElementSibling;
    if (body) body.style.display = headerEl.classList.contains('collapsed') ? 'none' : '';
}

async function changeReportCategory(reportId, categoryId) {
    try {
        const res = await window.ReportingEngine.api('PUT', `/api/reports/${reportId}`, {
            category_id: categoryId ? parseInt(categoryId) : null
        });
        if (!res.success) {
            alert('Failed to update category: ' + (res.message || 'Unknown error'));
        }
    } catch (e) {
        alert('Failed to update category: ' + e.message);
    }
}

async function addCategory() {
    const input = document.getElementById('category-name-input');
    const name = input.value.trim();
    if (!name) return;
    try {
        const res = await window.ReportingEngine.api('POST', '/api/categories', { name });
        if (res.success) {
            input.value = '';
            await refreshReportsList();
        } else {
            alert('Failed to add category: ' + (res.message || 'Unknown error'));
        }
    } catch (e) {
        alert('Failed to add category: ' + e.message);
    }
}

async function deleteCategory(categoryId, name) {
    if (!confirm(`Delete category "${name}"? Reports in this category will become uncategorized.`)) return;
    try {
        const res = await window.ReportingEngine.api('DELETE', `/api/categories/${categoryId}`);
        if (res.success) {
            await refreshReportsList();
        } else {
            alert('Failed to delete category: ' + (res.message || 'Unknown error'));
        }
    } catch (e) {
        alert('Failed to delete category: ' + e.message);
    }
}

async function refreshReportsList() {
    await initReportsList();
}

async function initConnectionsList() {
    try {
        const res = await window.ReportingEngine.api('GET', '/api/connections');
        const tbody = document.querySelector('#connections-table tbody');
        if (!res.data || res.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-muted">No connections yet. <a href="/connections/new">Create one</a></td></tr>';
            return;
        }
        tbody.innerHTML = res.data.map(c => `
            <tr>
                <td><a href="/connections/edit/${c.id}">${escapeHtml(c.name)}</a></td>
                <td>${escapeHtml(c.driver)}</td>
                <td>${escapeHtml(c.host || '-')}</td>
                <td>${escapeHtml(c.database)}</td>
                <td>${c.updated_at || '-'}</td>
                <td class="actions">
                    <a class="btn btn-sm" href="/connections/edit/${c.id}" title="Edit"><i class="ph-pencil"></i></a>
                    <button class="btn btn-sm" onclick="deleteConnection(${c.id})" title="Delete"><i class="ph-trash"></i></button>
                    <button class="btn btn-sm" onclick="testConnectionById(${c.id})" title="Test"><i class="ph-plug"></i></button>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        console.error('Connections list error:', e);
    }
}

async function initConnectionEdit() {
    const connId = document.getElementById('conn-id')?.value;
    if (!connId) return;
    try {
        const res = await window.ReportingEngine.api('GET', `/api/connections/${connId}`);
        if (!res.data) return;
        document.getElementById('conn-name').value = res.data.name || '';
        document.getElementById('conn-driver').value = res.data.driver || '';
        document.getElementById('conn-host').value = res.data.host || '';
        document.getElementById('conn-port').value = res.data.port || '';
        document.getElementById('conn-database').value = res.data.database || '';
        document.getElementById('conn-username').value = res.data.username || '';
        const opts = res.data.options;
        document.getElementById('conn-options').value = opts ? (typeof opts === 'object' ? JSON.stringify(opts, null, 2) : opts) : '';
        toggleConnectionFields();
    } catch (e) {
        console.error('Load connection error:', e);
    }
}

// Form handlers
document.addEventListener('submit', async (e) => {
    if (e.target.id === 'connection-form') {
        e.preventDefault();
        const id = document.getElementById('conn-id').value;
        const data = {
            name: document.getElementById('conn-name').value,
            driver: document.getElementById('conn-driver').value,
            host: document.getElementById('conn-host').value || null,
            port: document.getElementById('conn-port').value ? parseInt(document.getElementById('conn-port').value) : null,
            database: document.getElementById('conn-database').value,
            username: document.getElementById('conn-username').value || null,
            password: document.getElementById('conn-password').value || null,
            options: document.getElementById('conn-options').value || null,
        };
        try {
            const method = id ? 'PUT' : 'POST';
            const url = id ? `/api/connections/${id}` : '/api/connections';
            const res = await window.ReportingEngine.api(method, url, data);
            if (res.success) {
                window.location.href = '/connections';
            } else {
                alert('Error: ' + (res.message || 'Unknown error'));
            }
        } catch (err) {
            alert('Error saving connection');
        }
    }
});

async function testConnection() {
    const id = document.getElementById('conn-id').value;
    const resultDiv = document.getElementById('connection-test-result');
    resultDiv.style.display = 'block';
    resultDiv.className = 'test-result';
    resultDiv.textContent = 'Testing...';

    if (id) {
        await testConnectionById(id);
        return;
    }

    // Test unsaved connection
    const data = {
        driver: document.getElementById('conn-driver').value,
        host: document.getElementById('conn-host').value || null,
        port: document.getElementById('conn-port').value ? parseInt(document.getElementById('conn-port').value) : null,
        database: document.getElementById('conn-database').value,
        username: document.getElementById('conn-username').value || null,
        password: document.getElementById('conn-password').value || null,
    };

    try {
        const res = await window.ReportingEngine.api('POST', '/api/connections/0/test', data);
        resultDiv.className = 'test-result ' + (res.success ? 'success' : 'error');
        resultDiv.textContent = res.message || (res.success ? 'Connection successful!' : 'Connection failed');
    } catch (e) {
        resultDiv.className = 'test-result error';
        resultDiv.textContent = 'Test failed: ' + e.message;
    }
}

async function testConnectionById(id) {
    const resultDiv = document.getElementById('connection-test-result');
    try {
        const res = await window.ReportingEngine.api('POST', `/api/connections/${id}/test`);
        if (resultDiv) {
            resultDiv.style.display = 'block';
            resultDiv.className = 'test-result ' + (res.success ? 'success' : 'error');
            resultDiv.textContent = res.message || (res.success ? 'Connection successful!' : 'Connection failed');
        } else {
            alert(res.message || (res.success ? 'Connection successful!' : 'Connection failed'));
        }
    } catch (e) {
        if (resultDiv) {
            resultDiv.style.display = 'block';
            resultDiv.className = 'test-result error';
            resultDiv.textContent = 'Test failed';
        }
    }
}

async function deleteConnection(id) {
    if (!confirm('Delete this connection?')) return;
    await window.ReportingEngine.api('DELETE', `/api/connections/${id}`);
    initConnectionsList();
}

async function deleteReport(id) {
    if (!confirm('Delete this report?')) return;
    await window.ReportingEngine.api('DELETE', `/api/reports/${id}`);
    initReportsList();
}

async function importReportFile(event) {
    const file = event.target.files[0];
    if (!file) return;
    event.target.value = '';
    try {
        const text = await file.text();
        const data = JSON.parse(text);
        if (data.type !== 'report-export') {
            alert('Invalid export file. Expected a report export JSON.');
            return;
        }
        const res = await window.ReportingEngine.api('POST', '/api/reports/import', data);
        if (res.success && res.data) {
            window.location.href = `/reports/designer/${res.data.id}`;
        } else {
            alert('Import failed: ' + (res.message || 'Unknown error'));
        }
    } catch (e) {
        alert('Failed to import report: ' + e.message);
    }
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
