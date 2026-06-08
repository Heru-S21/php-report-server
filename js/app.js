window.ReportingEngine = {
    state: {
        activeReportId: null,
        definition: {},
        selectedElement: null,
        selectedBand: null,
        undoStack: [],
        redoStack: [],
        zoom: 1.0,
        isDirty: false,
        queryColumns: [],
    },
    listeners: {},
    dispatch(action, payload) {
        switch (action) {
            case 'SET_DEFINITION':
                this.state.definition = payload;
                this.state.isDirty = true;
                break;
            case 'SELECT_ELEMENT':
                this.state.selectedElement = payload;
                break;
            case 'SELECT_BAND':
                this.state.selectedBand = payload;
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
        if (body) options.body = JSON.stringify(body);
        const res = await fetch(url, options);
        return res.json();
    }
};

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
        const res = await window.ReportingEngine.api('GET', '/api/reports');
        const tbody = document.querySelector('#reports-table tbody');
        if (!res.data || res.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-muted">No reports yet. <a href="/reports/new">Create one</a></td></tr>';
            return;
        }
        tbody.innerHTML = res.data.map(r => `
            <tr>
                <td><a href="/reports/designer/${r.id}">${escapeHtml(r.name)}</a></td>
                <td>${escapeHtml(r.description || '-')}</td>
                <td>${r.connection_id || '-'}</td>
                <td>${r.updated_at || '-'}</td>
                <td class="actions">
                    <a class="btn btn-sm" href="/reports/designer/${r.id}" title="Design"><i class="ph-pencil"></i></a>
                    <a class="btn btn-sm" href="/reports/preview/${r.id}" title="Preview"><i class="ph-eye"></i></a>
                    <button class="btn btn-sm" onclick="deleteReport(${r.id})" title="Delete"><i class="ph-trash"></i></button>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        console.error('Reports list error:', e);
    }
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

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
