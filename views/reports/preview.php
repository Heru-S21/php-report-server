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
    <div class="preview-params" id="preview-params" style="display:none"></div>
    <div class="preview-container" id="preview-container">
        <div class="loading-spinner" id="preview-spinner">Loading preview...</div>
    </div>
</div>
<script>
const preview = {
    reportId: <?= json_encode($reportId) ?>,
    paramValues: {},
    async init() {
        if (!this.reportId) return;
        const res = await window.ReportingEngine.api('GET', `/api/reports/${this.reportId}`);
        if (!res.data) return;
        const def = typeof res.data.definition === 'string' ? JSON.parse(res.data.definition) : res.data.definition;
        const params = def.query?.parameters || [];
        if (params.length > 0) {
            this.renderParamForm(params);
        } else {
            this.loadPreview();
        }
    },
    renderParamForm(params) {
        const container = document.getElementById('preview-params');
        const output = document.getElementById('preview-container');
        container.style.display = 'block';
        output.innerHTML = '<div class="loading-spinner">Click "Run Preview" to load report data</div>';
        container.innerHTML = `
            <h4 style="margin-bottom:12px;font-size:14px;display:flex;align-items:center;gap:6px"><i class="ph-sliders"></i> Query Parameters</h4>
            <div class="form-row">
                ${params.map((p, i) => {
                    const name = typeof p === 'string' ? p : (p.name || '');
                    const type = typeof p === 'string' ? 'string' : (p.type || 'string');
                    const defaultValue = typeof p === 'string' ? '' : (p.defaultValue || '');
                    const inputId = 'param-' + i;
                    let inputHtml = '';
                    if (type === 'boolean') {
                        inputHtml = `<label style="font-weight:400;gap:6px;display:flex;align-items:center">
                            <input type="checkbox" id="${inputId}" class="param-input" data-param="${name}" ${defaultValue ? 'checked' : ''} onchange="preview.paramValues['${name}']=this.checked?'1':''">
                            ${name}
                        </label>`;
                    } else if (type === 'date') {
                        inputHtml = `<input type="date" id="${inputId}" class="form-control param-input" data-param="${name}" value="${defaultValue}" onchange="preview.paramValues['${name}']=this.value">`;
                    } else if (type === 'number') {
                        inputHtml = `<input type="number" id="${inputId}" class="form-control param-input" data-param="${name}" value="${defaultValue}" placeholder=":${name}" onchange="preview.paramValues['${name}']=this.value">`;
                    } else {
                        inputHtml = `<input type="text" id="${inputId}" class="form-control param-input" data-param="${name}" value="${defaultValue}" placeholder=":${name}" onchange="preview.paramValues['${name}']=this.value">`;
                    }
                    return type === 'boolean' ? inputHtml : `<div class="form-group" style="margin-bottom:0">
                        <label for="${inputId}">${name}</label>
                        ${inputHtml}
                    </div>`;
                }).join('')}
                <div class="form-group" style="margin-bottom:0;align-self:flex-end">
                    <button class="btn btn-primary" onclick="preview.loadPreview()"><i class="ph-play"></i> Run Preview</button>
                </div>
            </div>
        `;
        document.querySelectorAll('.param-input').forEach(inp => {
            const key = inp.dataset.param;
            if (inp.type === 'checkbox') {
                preview.paramValues[key] = inp.checked ? '1' : '';
            } else {
                preview.paramValues[key] = inp.value;
            }
        });
    },
    async loadPreview() {
        const container = document.getElementById('preview-container');
        container.innerHTML = '<div class="loading-spinner">Loading preview...</div>';
        const queryStr = Object.entries(this.paramValues)
            .filter(([,v]) => v !== '')
            .map(([k,v]) => `param_${k}=${encodeURIComponent(v)}`)
            .join('&');
        try {
            const url = `/api/render/${this.reportId}?format=html${queryStr ? '&' + queryStr : ''}`;
            const res = await fetch(url);
            const html = await res.text();
            container.innerHTML = html;
        } catch (e) {
            container.innerHTML = '<div class="error-message">Failed to load preview</div>';
        }
    },
    getParamQueryString() {
        return Object.entries(this.paramValues)
            .filter(([,v]) => v !== '')
            .map(([k,v]) => `param_${k}=${encodeURIComponent(v)}`)
            .join('&');
    },
    exportPdf() {
        if (!this.reportId) return;
        const qs = this.getParamQueryString();
        window.open(`/api/render/${this.reportId}?format=pdf${qs ? '&' + qs : ''}`, '_blank');
    },
    exportHtml() {
        if (!this.reportId) return;
        const qs = this.getParamQueryString();
        window.open(`/api/render/${this.reportId}?format=html${qs ? '&' + qs : ''}`, '_blank');
    },
};
document.addEventListener('DOMContentLoaded', () => preview.init());
</script>
