<div class="template-list-page">
    <div class="page-header">
        <h1>Report Templates</h1>
        <button class="btn btn-primary" onclick="showCreateTemplateModal()">
            <i class="ph-plus"></i> New Template
        </button>
    </div>
    <table class="table" id="templates-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Description</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <tr><td colspan="4" class="text-muted">Loading...</td></tr>
        </tbody>
    </table>
</div>

<!-- Create/Edit Template Modal -->
<div id="template-modal" class="modal" style="display:none">
    <div class="modal-backdrop" onclick="closeTemplateModal()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="template-modal-title">New Template</h3>
            <button class="btn btn-icon" onclick="closeTemplateModal()"><i class="ph-x"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="template-edit-id" value="">
            <div class="form-group">
                <label>Template Name</label>
                <input type="text" id="template-edit-name" class="form-control" placeholder="My Template">
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea id="template-edit-desc" class="form-control" rows="3" placeholder="Brief description"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn" onclick="closeTemplateModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveTemplate()">Save Template</button>
        </div>
    </div>
</div>

<script>
let templateEditId = null;

document.addEventListener('DOMContentLoaded', () => {
    if (document.querySelector('#templates-table')) initTemplateList();
});

async function initTemplateList() {
    try {
        const res = await window.ReportingEngine.api('GET', '/api/report-templates');
        const tbody = document.querySelector('#templates-table tbody');
        if (!res.data || res.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-muted">No templates yet.</td></tr>';
            return;
        }
        tbody.innerHTML = res.data.map(t => `
            <tr>
                <td><strong>${escapeHtml(t.name)}</strong></td>
                <td>${escapeHtml(t.description || '-')}</td>
                <td>${t.created_at || '-'}</td>
                <td class="actions">
                    <button class="btn btn-sm" onclick="editTemplate(${t.id})" title="Edit"><i class="ph-pencil"></i></button>
                    <a class="btn btn-sm" href="/reports/new?template=${t.id}" title="Use to create report"><i class="ph-file-plus"></i></a>
                    <button class="btn btn-sm" onclick="deleteTemplate(${t.id})" title="Delete"><i class="ph-trash"></i></button>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        console.error('Template list error:', e);
    }
}

function showCreateTemplateModal() {
    templateEditId = null;
    document.getElementById('template-modal-title').textContent = 'New Template';
    document.getElementById('template-edit-id').value = '';
    document.getElementById('template-edit-name').value = '';
    document.getElementById('template-edit-desc').value = '';
    document.getElementById('template-modal').style.display = 'flex';
}

async function editTemplate(id) {
    try {
        const res = await window.ReportingEngine.api('GET', `/api/report-templates/${id}`);
        if (!res.data) { alert('Template not found'); return; }
        templateEditId = id;
        document.getElementById('template-modal-title').textContent = 'Edit Template';
        document.getElementById('template-edit-id').value = id;
        document.getElementById('template-edit-name').value = res.data.name || '';
        document.getElementById('template-edit-desc').value = res.data.description || '';
        document.getElementById('template-modal').style.display = 'flex';
    } catch (e) {
        alert('Error loading template: ' + e.message);
    }
}

function closeTemplateModal() {
    document.getElementById('template-modal').style.display = 'none';
}

async function saveTemplate() {
    const name = document.getElementById('template-edit-name').value.trim();
    if (!name) { alert('Template name is required'); return; }
    const desc = document.getElementById('template-edit-desc').value.trim();
    try {
        if (templateEditId) {
            await window.ReportingEngine.api('PUT', `/api/report-templates/${templateEditId}`, { name, description: desc });
        } else {
            const def = {
                version: '1.0', name: name, description: desc, connectionId: null,
                page: { paperSize: 'A4', orientation: 'portrait', marginTop: 20, marginBottom: 20, marginLeft: 15, marginRight: 15 },
                query: { sql: '', visualJson: null, parameters: [] }, groups: [],
                bands: [
                    { type: 'page_header', height: 30, printOnEveryPage: true, backgroundColor: 'transparent', border: {}, elements: [] },
                    { type: 'report_header', height: 20, backgroundColor: 'transparent', border: {}, elements: [] },
                    { type: 'column_header', height: 16, backgroundColor: 'transparent', border: {}, elements: [] },
                    { type: 'detail', height: 16, backgroundColor: 'transparent', border: {}, elements: [] },
                    { type: 'report_footer', height: 22, backgroundColor: 'transparent', border: {}, elements: [] },
                    { type: 'page_footer', height: 16, printOnEveryPage: true, backgroundColor: 'transparent', border: {}, elements: [] },
                ],
                showGrid: true, snapToGrid: true, gridSize: 2,
                defaultStyle: { fontFamily: 'Arial', fontSize: 10, color: '#000000', backgroundColor: 'transparent' },
            };
            await window.ReportingEngine.api('POST', '/api/report-templates', { name, description: desc, definition: JSON.stringify(def) });
        }
        closeTemplateModal();
        initTemplateList();
    } catch (e) {
        alert('Error saving template: ' + e.message);
    }
}

async function deleteTemplate(id) {
    if (!confirm('Delete this template?')) return;
    await window.ReportingEngine.api('DELETE', `/api/report-templates/${id}`);
    initTemplateList();
}
</script>
