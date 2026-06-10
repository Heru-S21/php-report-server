<div class="template-edit-page">
    <div class="page-header">
        <h1 id="edit-title">Edit Template</h1>
        <a href="/templates" class="btn"><i class="ph-arrow-left"></i> Back</a>
    </div>
    <div class="form-card">
        <input type="hidden" id="template-id" value="<?= htmlspecialchars($templateId ?? '') ?>">
        <div class="form-group">
            <label>Template Name</label>
            <input type="text" id="template-name" class="form-control" placeholder="My Template">
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea id="template-description" class="form-control" rows="3" placeholder="Brief description"></textarea>
        </div>
        <div class="form-group">
            <label>Definition (JSON)</label>
            <textarea id="template-definition" class="form-control" rows="20" style="font-family:var(--font-mono);font-size:12px;resize:vertical" placeholder="{}"></textarea>
        </div>
        <div style="display:flex;gap:8px;margin-top:12px">
            <button class="btn btn-primary" onclick="saveTemplateEdit()"><i class="ph-floppy-disk"></i> Save</button>
            <a href="/templates" class="btn">Cancel</a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', async () => {
    const templateId = document.getElementById('template-id').value;
    if (!templateId) return;
    try {
        const res = await window.ReportingEngine.api('GET', `/api/report-templates/${templateId}`);
        if (!res.data) { alert('Template not found'); return; }
        document.getElementById('template-name').value = res.data.name || '';
        document.getElementById('template-description').value = res.data.description || '';
        const def = typeof res.data.definition === 'string' ? res.data.definition : JSON.stringify(res.data.definition, null, 2);
        document.getElementById('template-definition').value = def;
    } catch (e) {
        alert('Error loading template: ' + e.message);
    }
});

async function saveTemplateEdit() {
    const id = document.getElementById('template-id').value;
    const name = document.getElementById('template-name').value.trim();
    if (!name) { alert('Name is required'); return; }
    const description = document.getElementById('template-description').value.trim();
    const definition = document.getElementById('template-definition').value;
    try {
        const payload = { name, description, definition };
        if (id) {
            await window.ReportingEngine.api('PUT', `/api/report-templates/${id}`, payload);
        } else {
            await window.ReportingEngine.api('POST', '/api/report-templates', payload);
        }
        window.location.href = '/templates';
    } catch (e) {
        alert('Error saving: ' + e.message);
    }
}
</script>
