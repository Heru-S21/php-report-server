<div class="preview-page">
    <div class="page-header">
        <h1>Report Preview</h1>
        <div class="preview-actions">
            <button class="btn" onclick="window.location.href='/reports/designer/<?= htmlspecialchars($reportId ?? '') ?>'">
                <i class="ph-arrow-left"></i> Back to Designer
            </button>
            <button class="btn" onclick="preview.exportPdf()"><i class="ph-file-pdf"></i> Export PDF</button>
            <button class="btn" onclick="preview.exportHtml()"><i class="ph-file-html"></i> Export HTML</button>
        </div>
    </div>
    <div class="preview-container" id="preview-container">
        <div class="loading-spinner">Loading preview...</div>
    </div>
</div>
<script>
const preview = {
    reportId: <?= json_encode($reportId) ?>,
    async init() {
        if (!this.reportId) return;
        const res = await window.ReportingEngine.api('GET', `/api/reports/${this.reportId}`);
        if (!res.data) return;
        const def = typeof res.data.definition === 'string' ? JSON.parse(res.data.definition) : res.data.definition;
        const params = def.query?.parameters || [];
        const paramContainer = document.getElementById('preview-params');
        if (params.length > 0) {
            paramContainer.style.display = 'block';
            paramContainer.innerHTML = `
                <h4 style="margin-bottom:8px;font-size:14px">Query Parameters</h4>
                <div class="form-row">
                    ${params.map(p => `
                        <div class="form-group" style="margin-bottom:0">
                            <label>${p}</label>
                            <input class="form-control param-input" data-param="${p}" placeholder=":${p}">
                        </div>
                    `).join('')}
                    <button class="btn btn-primary" onclick="preview.loadPreview()">Run Preview</button>
                </div>
            `;
        }
        this.loadPreview();
    },
    async loadPreview() {
        const container = document.getElementById('preview-container');
        container.innerHTML = '<div class="loading-spinner">Loading preview...</div>';
        const paramInputs = document.querySelectorAll('.param-input');
        const params = {};
        paramInputs.forEach(inp => { params[inp.dataset.param] = inp.value; });
        const queryStr = Object.entries(params).map(([k,v]) => `param_${k}=${encodeURIComponent(v)}`).join('&');
        try {
            const res = await fetch(`/api/render/${this.reportId}?format=html&${queryStr}`);
            const html = await res.text();
            container.innerHTML = html;
        } catch (e) {
            container.innerHTML = '<div class="error-message">Failed to load preview</div>';
        }
    },
    exportPdf() {
        if (!this.reportId) return;
        const paramInputs = document.querySelectorAll('.param-input');
        const params = {};
        paramInputs.forEach(inp => { params[inp.dataset.param] = inp.value; });
        const queryStr = Object.entries(params).map(([k,v]) => `param_${k}=${encodeURIComponent(v)}`).join('&');
        window.open(`/api/render/${this.reportId}?format=pdf&${queryStr}`, '_blank');
    },
    exportHtml() {
        if (!this.reportId) return;
        const paramInputs = document.querySelectorAll('.param-input');
        const params = {};
        paramInputs.forEach(inp => { params[inp.dataset.param] = inp.value; });
        const queryStr = Object.entries(params).map(([k,v]) => `param_${k}=${encodeURIComponent(v)}`).join('&');
        window.open(`/api/render/${this.reportId}?format=html&${queryStr}`, '_blank');
    },
};
document.addEventListener('DOMContentLoaded', () => preview.init());
</script>
