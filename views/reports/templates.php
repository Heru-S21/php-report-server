<div class="template-picker-page">
    <div class="page-header">
        <h1>New Report</h1>
        <p class="text-muted">Choose a template to get started</p>
    </div>
    <div id="template-grid" class="template-grid">
        <div class="text-muted" style="text-align:center;padding:40px">Loading templates...</div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', async () => {
    const grid = document.getElementById('template-grid');
    try {
        const res = await window.ReportingEngine.api('GET', '/api/report-templates');
        if (!res.data || res.data.length === 0) {
            grid.innerHTML = '<div class="text-muted" style="text-align:center;padding:40px">No templates available. <a href="/reports/designer?blank=1">Create a blank report</a></div>';
            return;
        }
        const icons = ['ph-file-plus', 'ph-chart-bar', 'ph-list-bullets', 'ph-calculator'];
        const colors = ['#6366f1', '#f59e0b', '#22c55e', '#3b82f6'];
        let html = res.data.map((t, i) => `
            <div class="template-card" onclick="createFromTemplate(${t.id})" title="${escapeHtml(t.description || '')}">
                <div class="template-card-icon" style="background:${colors[i % colors.length]}15;color:${colors[i % colors.length]}">
                    <i class="${icons[i % icons.length] || 'ph-file-text'}"></i>
                </div>
                <div class="template-card-body">
                    <h3>${escapeHtml(t.name)}</h3>
                    <p>${escapeHtml(t.description || 'No description')}</p>
                </div>
            </div>
        `).join('');
        html += `
            <div class="template-card template-card-blank" onclick="createBlankReport()">
                <div class="template-card-icon" style="background:#e2e8f0;color:#64748b">
                    <i class="ph-file-plus"></i>
                </div>
                <div class="template-card-body">
                    <h3>Blank Report</h3>
                    <p>Start from scratch with an empty layout</p>
                </div>
            </div>
        `;
        grid.innerHTML = html;
    } catch (e) {
        grid.innerHTML = '<div class="text-muted" style="text-align:center;padding:40px;color:var(--color-danger)">Failed to load templates. <a href="/reports/designer?blank=1">Create a blank report</a></div>';
    }
});

async function createBlankReport() {
    try {
        const def = {
            version: '1.0', name: 'Untitled Report', description: '', connectionId: null,
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
        const createRes = await window.ReportingEngine.api('POST', '/api/reports', {
            name: 'Untitled Report',
            description: '',
            definition: JSON.stringify(def),
        });
        if (createRes.data && createRes.data.id) {
            window.location.href = `/reports/designer/${createRes.data.id}`;
        } else {
            alert('Failed to create report: ' + (createRes.message || 'Unknown error'));
        }
    } catch (e) {
        alert('Error creating report: ' + e.message);
    }
}

async function createFromTemplate(templateId) {
    try {
        const res = await window.ReportingEngine.api('GET', `/api/report-templates/${templateId}`);
        if (!res.data) { alert('Failed to load template'); return; }
        const def = typeof res.data.definition === 'string' ? JSON.parse(res.data.definition) : res.data.definition;
        def.name = 'Untitled Report';
        def.description = '';
        def.connectionId = null;
        def.query = { sql: def.query?.sql || '', visualJson: null, parameters: [] };

        const createRes = await window.ReportingEngine.api('POST', '/api/reports', {
            name: def.name,
            description: '',
            definition: JSON.stringify(def),
        });
        if (createRes.data && createRes.data.id) {
            window.location.href = `/reports/designer/${createRes.data.id}`;
        } else {
            alert('Failed to create report: ' + (createRes.message || 'Unknown error'));
        }
    } catch (e) {
        alert('Error creating report: ' + e.message);
    }
}
</script>
