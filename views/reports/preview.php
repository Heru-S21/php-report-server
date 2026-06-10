<?php
$contentBefore = '<div class="preview-toolbar">
    <div class="navbar-brand">
        <i class="ph-eye"></i>
        <span>Report Preview</span>
    </div>
    <div class="navbar-nav" style="flex:0"></div>
    <div class="navbar-actions">
        <button class="nav-link" onclick="window.location.href=\'/reports/designer/' . htmlspecialchars($reportId ?? '') . '\'" style="cursor:pointer">
            <i class="ph-arrow-left"></i> Back
        </button>
        <button class="nav-link" onclick="preview.exportPdf()" style="cursor:pointer"><i class="ph-file-pdf"></i> PDF</button>
        <button class="nav-link" onclick="preview.exportHtml()" style="cursor:pointer"><i class="ph-file-html"></i> HTML</button>
    </div>
</div>
<div class="preview-ruler-wrap" id="preview-ruler-wrap">
    <div class="preview-ruler" id="preview-ruler"></div>
</div>';
?>
<div class="preview-page">
    <div class="preview-params" id="preview-params" style="display:none"></div>
    <div class="preview-paper" id="preview-paper">
        <div class="preview-container" id="preview-container">
            <div class="loading-spinner" id="preview-spinner">Loading preview...</div>
        </div>
    </div>
</div>
<script>
const preview = {
    reportId: <?= json_encode($reportId) ?>,
    paramValues: {},
    isUnsaved: new URLSearchParams(window.location.search).get('unsaved') === '1',
    async init() {
        this.renderRuler();
        if (!this.reportId) return;
        let def;
        if (this.isUnsaved) {
            const stored = localStorage.getItem('designer_draft_' + this.reportId);
            if (!stored) {
                document.getElementById('preview-container').innerHTML = '<div class="error-message">Unsaved preview data not found. Please go back to the designer and try again.</div>';
                return;
            }
            def = JSON.parse(stored);
            this.definition = def;
        } else {
            const res = await fetch('/api/reports/' + this.reportId);
            const json = await res.json();
            if (!json.data) { document.getElementById('preview-container').innerHTML = '<div class="error-message">Report not found</div>'; return; }
            def = typeof json.data.definition === 'string' ? JSON.parse(json.data.definition) : json.data.definition;
            this.definition = def;
        }
        const params = def.query?.parameters || [];
        if (params.length > 0) {
            this.renderParamForm(params);
        } else {
            this.loadPreview();
        }
    },
    renderRuler() {
        const ruler = document.getElementById('preview-ruler');
        if (!ruler) return;
        const paperWidth = 210;
        const marginLeft = 15;
        const marginRight = 15;
        const usableWidth = paperWidth - marginLeft - marginRight;
        const pxPerMm = ruler.offsetWidth / paperWidth;
        let html = '';
        for (let mm = 0; mm <= paperWidth; mm += 1) {
            const x = mm * pxPerMm;
            if (mm % 10 === 0) {
                html += `<div class="ruler-mark" style="left:${x}px;height:12px"></div>`;
                html += `<div class="ruler-label" style="left:${x}px">${mm}</div>`;
            } else if (mm % 5 === 0) {
                html += `<div class="ruler-mark" style="left:${x}px;height:8px;top:4px"></div>`;
            } else {
                html += `<div class="ruler-mark" style="left:${x}px;height:4px;top:8px;background:#cbd5e1"></div>`;
            }
        }
        const ml = marginLeft * pxPerMm;
        const mr = (paperWidth - marginRight) * pxPerMm;
        html += `<div style="position:absolute;top:0;left:${ml}px;width:${mr - ml}px;height:100%;border-left:1px solid #93c5fd;border-right:1px solid #93c5fd;pointer-events:none"></div>`;
        ruler.innerHTML = html;
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
        const oldStyles = document.getElementById('preview-styles');
        if (oldStyles) oldStyles.remove();
        container.innerHTML = '<div class="loading-spinner">Loading preview...</div>';
        try {
            let html;
            if (this.isUnsaved) {
                const body = { definition: this.definition, format: 'html' };
                Object.entries(this.paramValues).forEach(([k,v]) => { if (v !== '') body['param_' + k] = v; });
                const res = await fetch('/api/render/preview', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });
                html = await res.text();
            } else {
                const qs = Object.entries(this.paramValues)
                    .filter(([,v]) => v !== '')
                    .map(([k,v]) => `param_${k}=${encodeURIComponent(v)}`)
                    .join('&');
                const url = `/api/render/${this.reportId}?format=html${qs ? '&' + qs : ''}`;
                const res = await fetch(url);
                html = await res.text();
            }
            const styleMatch = html.match(/<style[^>]*>([\s\S]*?)<\/style>/i);
            if (styleMatch) {
                const cleanCss = styleMatch[1].replace(/body\s*\{[^}]*\}/g, '');
                const styleEl = document.createElement('style');
                styleEl.id = 'preview-styles';
                styleEl.textContent = cleanCss;
                document.head.appendChild(styleEl);
            }
            html = html.replace(/^<!DOCTYPE[^>]*>/i, '');
            html = html.replace(/<html[^>]*>/gi, '');
            html = html.replace(/<\/html>/gi, '');
            html = html.replace(/<head[^>]*>[\s\S]*?<\/head>/gi, '');
            html = html.replace(/^[\s\S]*?<body[^>]*>/i, '');
            html = html.replace(/<\/body>/gi, '');
            container.innerHTML = html;
            this.renderRuler();
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
    postRenderRequest(format) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/api/render/preview';
        form.target = '_blank';
        const defInput = document.createElement('input');
        defInput.type = 'hidden'; defInput.name = 'json'; defInput.value = JSON.stringify(this.definition);
        form.appendChild(defInput);
        const fmtInput = document.createElement('input');
        fmtInput.type = 'hidden'; fmtInput.name = 'format'; fmtInput.value = format;
        form.appendChild(fmtInput);
        Object.entries(this.paramValues).forEach(([k,v]) => {
            if (v === '') return;
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'param_' + k; input.value = v;
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
        form.remove();
    },
    exportPdf() {
        if (!this.reportId) return;
        if (this.isUnsaved) { this.postRenderRequest('pdf'); return; }
        const qs = this.getParamQueryString();
        window.open(`/api/render/${this.reportId}?format=pdf${qs ? '&' + qs : ''}`, '_blank');
    },
    exportHtml() {
        if (!this.reportId) return;
        if (this.isUnsaved) { this.postRenderRequest('html'); return; }
        const qs = this.getParamQueryString();
        window.open(`/api/render/${this.reportId}?format=html${qs ? '&' + qs : ''}`, '_blank');
    },
};
document.addEventListener('DOMContentLoaded', () => preview.init());
</script>
