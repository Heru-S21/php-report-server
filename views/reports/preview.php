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
        try {
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
                const ct = res.headers.get('content-type') || '';
                if (!ct.includes('application/json')) {
                    const text = await res.text();
                    document.getElementById('preview-container').innerHTML = '<div class="error-message">Failed to load report: server returned ' + ct + '</div>';
                    return;
                }
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
        } catch (e) {
            document.getElementById('preview-container').innerHTML = '<div class="error-message">Failed to load preview: ' + e.message + '</div>';
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
                    const options = typeof p === 'string' ? [] : (Array.isArray(p.options) ? p.options : []);
                    const dependsOn = typeof p === 'string' ? '' : (p.dependsOn || '');
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
                    } else if (type === 'dropdown') {
                        inputHtml = `<select id="${inputId}" class="form-control param-input" data-param="${name}" data-depends="${dependsOn}" onchange="preview.paramValues['${name}']=this.value">
                            <option value="">Select...</option>
                            ${options.map(o => `<option value="${o}"${defaultValue === o ? ' selected' : ''}>${o}</option>`).join('')}
                        </select>`;
                    } else if (type === 'multi-select') {
                        inputHtml = `<div id="${inputId}" class="param-input multi-select-group" data-param="${name}" data-depends="${dependsOn}">
                            ${options.map(o => `<label style="font-weight:400;display:flex;align-items:center;gap:4px;font-size:12px">
                                <input type="checkbox" value="${o}"${defaultValue === o ? ' checked' : ''}
                                    onchange="preview.updateMultiSelect('${name}')"> ${o}
                            </label>`).join('')}
                        </div>`;
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
            if (inp.type === 'checkbox' && !inp.closest('.multi-select-group')) {
                preview.paramValues[key] = inp.checked ? '1' : '';
            } else if (inp.classList.contains('multi-select-group')) {
                preview.updateMultiSelect(key);
            } else if (inp.tagName === 'SELECT' || inp.type === 'text' || inp.type === 'number' || inp.type === 'date') {
                preview.paramValues[key] = inp.value;
            }
        });
        // Attach cascade handlers
        document.querySelectorAll('.param-input[data-depends]').forEach(inp => {
            const depends = inp.dataset.depends;
            if (!depends) return;
            const parentInput = document.querySelector(`.param-input[data-param="${depends}"]`);
            if (parentInput) {
                parentInput.addEventListener('change', () => {
                    inp.disabled = true;
                    inp.style.opacity = '0.5';
                });
            }
        });
        preview._cascadeParams = params;
    },

    updateMultiSelect(name) {
        const group = document.querySelector(`.param-input.multi-select-group[data-param="${name}"]`);
        if (!group) return;
        const checked = group.querySelectorAll('input[type="checkbox"]:checked');
        preview.paramValues[name] = Array.from(checked).map(cb => cb.value).join(',');
    },
    async loadPreview() {
        const container = document.getElementById('preview-container');
        const oldStyles = document.getElementById('preview-styles');
        if (oldStyles) oldStyles.remove();
        container.innerHTML = '<div class="loading-spinner">Loading preview...</div>';
        try {
            let html;
            const body = { definition: this.definition, format: 'html', no_print: '1' };
            const fontMetrics = this.measureFontMetrics(this.definition);
            if (Object.keys(fontMetrics).length > 0) body._fontMetrics = fontMetrics;
            Object.entries(this.paramValues).forEach(([k,v]) => { if (v !== '') body['param_' + k] = v; });
            const res = await fetch('/api/render/preview', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            html = await res.text();
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
            this.fixWordWrapHeights();
        } catch (e) {
            container.innerHTML = '<div class="error-message">Failed to load preview</div>';
        }
    },
    fixWordWrapHeights() {
        const container = document.getElementById('preview-container');
        container.querySelectorAll('.band').forEach(band => {
            const bandHStr = band.style.height;
            if (!bandHStr) return;
            const bandH = parseFloat(bandHStr);
            let maxBottom = 0;
            let grew = false;

            band.querySelectorAll('.element').forEach(el => {
                const span = el.querySelector('span');
                if (!span) return;
                if (getComputedStyle(span).whiteSpace !== 'normal') return;

                const top = parseFloat(el.style.top);
                const w = parseFloat(el.style.width);
                const h = parseFloat(el.style.height);
                if (isNaN(top) || isNaN(w) || isNaN(h)) return;

                const chPx = span.scrollHeight;
                if (!chPx) return;

                const wPx = el.offsetWidth;
                if (!wPx) return;
                const pxPerMm = wPx / w;
                const chMm = chPx / pxPerMm;
                const nh = Math.max(h, chMm);

                if (nh > h) {
                    el.style.height = nh + 'mm';
                    grew = true;
                }

                const bottom = top + nh;
                if (bottom > maxBottom) maxBottom = bottom;
            });

            if (grew && maxBottom > bandH) {
                band.style.height = maxBottom + 'mm';
            }
        });
    },
    measureFontMetrics(definition) {
        const allBands = [];
        if (definition.bands) {
            for (const key in definition.bands) allBands.push(definition.bands[key]);
        }
        const combos = new Map();
        for (const band of allBands) {
            if (!band || !band.elements) continue;
            for (const el of band.elements) {
                if (el.wordWrap && !['image', 'line', 'rect', 'barcode'].includes(el.type)) {
                    const ff = el.fontFamily || 'Arial';
                    const fs = el.fontSize || 10;
                    const b = el.bold ? '1' : '0';
                    const it = el.italic ? '1' : '0';
                    const key = ff + '-' + fs + '-' + b + '-' + it;
                    if (!combos.has(key)) {
                        combos.set(key, { fontFamily: ff, fontSize: fs, bold: !!el.bold, italic: !!el.italic });
                    }
                }
            }
        }
        if (combos.size === 0) return {};
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        const results = {};
        const sample = 'abcdefghijklmnopqrstuvwxyz0123456789@.-_';
        for (const [key, c] of combos) {
            let fontStr = c.fontSize + 'pt ' + c.fontFamily;
            if (c.bold) fontStr = 'bold ' + fontStr;
            if (c.italic) fontStr = 'italic ' + fontStr;
            ctx.font = fontStr;
            const avgPx = ctx.measureText(sample).width / sample.length;
            results[key] = avgPx * 25.4 / 96;
        }
        return results;
    },
    getParamQueryString() {
        return Object.entries(this.paramValues)
            .filter(([,v]) => v !== '')
            .map(([k,v]) => `param_${k}=${encodeURIComponent(v)}`)
            .join('&');
    },
    postRenderRequest(format) {
        if (!this.definition) { document.getElementById('preview-container').innerHTML = '<div class="error-message">Report definition not loaded. Please reload the page.</div>'; return; }
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
        const fontMetrics = this.measureFontMetrics(this.definition);
        if (Object.keys(fontMetrics).length > 0) {
            const fmInput = document.createElement('input');
            fmInput.type = 'hidden'; fmInput.name = '_fontMetrics'; fmInput.value = JSON.stringify(fontMetrics);
            form.appendChild(fmInput);
        }
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
        if (!this.definition) { document.getElementById('preview-container').innerHTML = '<div class="error-message">Report definition not loaded. Please reload the page.</div>'; return; }
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
