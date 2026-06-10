function getInstalledFonts() {
    const fonts = [
        'Arial', 'Arial Black', 'Arial Narrow', 'Arial Rounded MT Bold',
        'Bahnschrift', 'Bauhaus 93', 'Bell MT', 'Berlin Sans FB', 'Bernard MT Condensed',
        'Blackadder ITC', 'Bodoni MT', 'Book Antiqua', 'Bookman Old Style',
        'Bradley Hand ITC', 'Calibri', 'Californian FB', 'Calisto MT', 'Cambria',
        'Candara', 'Castellar', 'Centaur', 'Century', 'Century Gothic', 'Century Schoolbook',
        'Chiller', 'Colonna MT', 'Comic Sans MS', 'Consolas', 'Constantia', 'Cooper Black',
        'Copperplate Gothic', 'Corbel', 'Cordia New', 'Courier New', 'Curlz MT',
        'DejaVu Sans', 'DejaVu Sans Mono', 'DejaVu Serif',
        'Ebrima', 'Edwardian Script ITC', 'Elephant', 'Engravers MT', 'Eras ITC',
        'Felix Titling', 'Footlight MT', 'Franklin Gothic', 'Freestyle Script', 'French Script MT',
        'Gabriola', 'Gadugi', 'Garamond', 'Gigi', 'Gill Sans', 'Gill Sans MT',
        'Gloucester MT', 'Goudy Old Style', 'Goudy Stout', 'Great Vibes',
        'Haettenschweiler', 'Harlow Solid', 'Harrington', 'HoloLens MDL2 Assets',
        'Impact', 'Imprint MT Shadow', 'Informal Roman', 'Ink Free', 'Italian Old Style',
        'Javanese Text', 'Jokerman', 'Juice ITC',
        'Kristen ITC', 'Kunstler Script',
        'Lao UI', 'Leelawadee', 'Leelawadee UI', 'Liberation Mono', 'Liberation Sans',
        'Liberation Sans Narrow', 'Liberation Serif', 'Lucida Bright', 'Lucida Calligraphy',
        'Lucida Console', 'Lucida Fax', 'Lucida Handwriting', 'Lucida Sans',
        'Lucida Sans Typewriter', 'Lucida Sans Unicode',
        'Magneto', 'Maiandra GD', 'Malgun Gothic', 'Marlett', 'Matura MT Script Capitals',
        'Microsoft Himalaya', 'Microsoft JhengHei', 'Microsoft New Tai Lue',
        'Microsoft PhagsPa', 'Microsoft Sans Serif', 'Microsoft Tai Le',
        'Microsoft YaHei', 'Microsoft Yi Baiti', 'MingLiU', 'Mistral', 'Modern No. 20',
        'Mongolian Baiti', 'Monotype Corsiva', 'MS Gothic', 'MS Outlook', 'MS Reference Sans Serif',
        'MS Reference Specialty', 'MT Extra', 'MV Boli',
        'Myanmar Text', 'Narkisim', 'Niagara Engraved', 'Niagara Solid',
        'Noto Sans', 'Noto Sans Mono', 'Noto Serif', 'NSimSun',
        'OCR A Extended', 'Old English Text MT', 'Onyx', 'Open Sans', 'Optima',
        'Palace Script MT', 'Palatino Linotype', 'Papyrus', 'Parchment', 'Perpetua',
        'Perpetua Titling MT', 'Playbill', 'Poor Richard', 'Pristina', 'PT Sans', 'PT Serif',
        'Quicksand', 'Rage Italic', 'Ravie', 'Roboto', 'Roboto Mono', 'Roboto Slab',
        'Rockwell', 'Rockwell Extra Bold', 'Script MT Bold', 'Segoe MDL2 Assets',
        'Segoe Print', 'Segoe Script', 'Segoe UI', 'Segoe UI Emoji', 'Segoe UI Historic',
        'Segoe UI Symbol', 'Showcard Gothic', 'SimSun', 'Sitka', 'Snap ITC', 'Source Code Pro',
        'Source Sans Pro', 'Source Serif Pro', 'Stencil', 'Sylfaen', 'Symbol',
        'Tahoma', 'Tempus Sans ITC', 'Times New Roman', 'Trebuchet MS', 'Tw Cen MT',
        'Ubuntu', 'Ubuntu Condensed', 'Ubuntu Mono',
        'Verdana', 'Viner Hand ITC', 'Vivaldi', 'Vladimir Script', 'Webdings',
        'Wide Latin', 'Wingdings', 'Wingdings 2', 'Wingdings 3',
    ];
    if (typeof document === 'undefined') return fonts;
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    const testStr = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    ctx.font = '72px serif';
    const base = ctx.measureText(testStr).width;
    return fonts.filter(name => {
        ctx.font = `72px "${name}", serif`;
        return ctx.measureText(testStr).width !== base;
    });
}

function renderFontOptions(selected) {
    const standard = ['Arial', 'Helvetica', 'Times New Roman', 'Courier New', 'Georgia', 'Verdana'];
    const selectedFont = selected || 'Arial';
    const installed = getInstalledFonts().filter(f => !standard.includes(f));
    let html = '<optgroup label="Standard Fonts">';
    for (const name of standard) {
        const sel = selectedFont === name ? ' selected' : '';
        html += `<option value="${name}"${sel}>${name}</option>`;
    }
    html += '</optgroup>';
    if (installed.length) {
        html += '<optgroup label="System Fonts">';
        for (const name of installed) {
            const sel = selectedFont === name ? ' selected' : '';
            html += `<option value="${name}"${sel}>${name}</option>`;
        }
        html += '</optgroup>';
    }
    return html;
}

class ElementEditor {
    constructor(designer) {
        this.designer = designer;
        this.contentEl = document.getElementById('properties-content');
        this.currentElement = null;
        this.currentBand = null;
        this.activeTab = 'general';
        this.borderEditor = new BorderEditor(designer);
        window.borderEditor = this.borderEditor;
    }

    loadElement(element) {
        this.currentElement = element;
        this.currentBand = null;
        this.render();
    }

    loadBand(band) {
        this.currentBand = band;
        this.currentElement = null;
        this.render();
    }

    switchTab(tab) {
        this.activeTab = tab;
        document.querySelectorAll('.properties-tabs .tab').forEach(t => {
            t.classList.toggle('active', t.dataset.tab === tab);
        });
        this.render();
    }

    loadReport() {
        this.currentElement = null;
        this.currentBand = null;
        this.render();
    }

    render() {
        if (this.currentElement) {
            this.renderElementProps();
        } else if (this.currentBand) {
            this.renderBandProps();
        } else {
            this.renderReportProps();
        }
    }

    renderElementProps() {
        const el = this.currentElement;
        if (!el) return;

        switch (this.activeTab) {
            case 'general': this.renderGeneralTab(el); break;
            case 'style': this.renderStyleTab(el); break;
            case 'border': this.borderEditor.render(this.contentEl, el); break;
            case 'advanced': this.renderAdvancedTab(el); break;
        }
    }

    renderBandProps() {
        const band = this.currentBand;
        if (!band) return;

        switch (this.activeTab) {
            case 'general': this.renderBandGeneralTab(band); break;
            case 'border': this.borderEditor.render(this.contentEl, band); break;
            default:
                this.contentEl.innerHTML = '<p class="text-muted">Band properties</p>';
                break;
        }
    }

    renderGeneralTab(el) {
        const fieldOptions = (window.ReportingEngine.state.queryColumns || [])
            .map(c => `<option value="${c.name}" ${el.fieldName === c.name ? 'selected' : ''}>${c.name}</option>`)
            .join('');

        const formatHelp = el.type === 'field' || el.type === 'aggregate'
            ? '<small style="color:#64748b">Format: %,2f, %d, Y-m-d, etc.</small>' : '';

        this.contentEl.innerHTML = `
            <div class="prop-group">
                <label>Type</label>
                <div style="font-size:13px;color:#64748b">${el.type}</div>
            </div>
            <div class="prop-group">
                <label>Text / Label</label>
                <input class="prop-control" type="text" value="${escapeHtml(el.text || '')}"
                       onchange="window.elementEditor.updateField('text', this.value)">
            </div>
            ${el.type === 'label' ? `
            <div class="prop-group">
                <label>Expression</label>
                <textarea class="prop-control" rows="3" style="font-family:var(--font-mono);font-size:12px;resize:vertical"
                    onchange="window.elementEditor.updateField('expression', this.value)"
                    placeholder='[field] > 3 ? "more" : "less"'>${escapeHtml(el.expression || '')}</textarea>
                <small style="color:#64748b;font-size:11px">Use [fieldName] to reference fields. Supports &gt; &lt; == != ? : + - * /</small>
            </div>` : ''}
            ${el.type === 'field' || el.type === 'aggregate' ? `
            <div class="prop-group">
                <label>Field Name</label>
                <select class="prop-control" onchange="window.elementEditor.updateField('fieldName', this.value)">
                    <option value="">Select field...</option>
                    ${fieldOptions}
                </select>
            </div>` : ''}
            ${el.type === 'aggregate' ? `
            <div class="prop-group">
                <label>Function</label>
                <select class="prop-control" onchange="window.elementEditor.updateField('aggregateFunc', this.value)">
                    <option value="sum" ${el.aggregateFunc === 'sum' ? 'selected' : ''}>SUM</option>
                    <option value="avg" ${el.aggregateFunc === 'avg' ? 'selected' : ''}>AVG</option>
                    <option value="count" ${el.aggregateFunc === 'count' ? 'selected' : ''}>COUNT</option>
                    <option value="min" ${el.aggregateFunc === 'min' ? 'selected' : ''}>MIN</option>
                    <option value="max" ${el.aggregateFunc === 'max' ? 'selected' : ''}>MAX</option>
                </select>
            </div>
            <div class="prop-group">
                <label>Scope</label>
                <select class="prop-control" onchange="window.elementEditor.updateField('aggregateScope', this.value)">
                    <option value="group" ${el.aggregateScope === 'group' ? 'selected' : ''}>Group</option>
                    <option value="report" ${el.aggregateScope === 'report' ? 'selected' : ''}>Report</option>
                </select>
            </div>` : ''}
            <div class="prop-group">
                <label>Format</label>
                <input class="prop-control" type="text" value="${escapeHtml(el.format || '')}"
                       onchange="window.elementEditor.updateField('format', this.value)" placeholder="${el.type === 'datetime' ? 'Y-m-d' : '#,##0.00'}">
                ${formatHelp}
            </div>
            <div class="prop-group">
                <label>Conditional Visibility</label>
                <input class="prop-control" type="text" value="${escapeHtml(el.conditionalExpression || '')}"
                       onchange="window.elementEditor.updateField('conditionalExpression', this.value)"
                       placeholder='[field] == "active"'>
                <small style="color:#64748b;font-size:11px">Element hidden when expression is false/empty</small>
            </div>
            ${el.type === 'image' ? `
            <div class="prop-group">
                <label>Image Source</label>
                <div style="display:flex;gap:4px;align-items:stretch">
                    <input class="prop-control" type="text" value="${escapeHtml(el.imageUrl || '')}"
                           onchange="window.elementEditor.updateField('imageUrl', this.value)"
                           placeholder="External URL or select from library" style="flex:1">
                    <button class="btn btn-sm" onclick="window.imagePicker.open(function(url){ window.elementEditor.updateField('imageUrl', url); window.elementEditor.render(); })" title="Browse images"><i class="ph-image"></i></button>
                </div>
            </div>
            <div class="prop-group" id="image-preview-container" style="${el.imageUrl ? '' : 'display:none'}">
                <label>Preview</label>
                <div style="border:1px solid var(--color-border);border-radius:4px;padding:4px;background:#fff;text-align:center;max-height:120px;overflow:hidden">
                    <img src="${escapeHtml(el.imageUrl || '')}" style="max-width:100%;max-height:110px;object-fit:contain" onerror="this.parentElement.innerHTML='<span style=color:#dc2626;font-size:12px>Failed to load</span>'">
                </div>
            </div>
            <div class="prop-group">
                <label>Display</label>
                <select class="prop-control" onchange="window.elementEditor.updateField('imageDisplay', this.value)">
                    <option value="proportional" ${(el.imageDisplay||'proportional') === 'proportional' ? 'selected' : ''}>Proportional</option>
                    <option value="stretch" ${el.imageDisplay === 'stretch' ? 'selected' : ''}>Stretch</option>
                    <option value="original" ${el.imageDisplay === 'original' ? 'selected' : ''}>Original</option>
                </select>
            </div>` : ''}
            <div class="prop-row">
                <div class="prop-group">
                    <label>Top (mm)</label>
                    <input class="prop-control" type="number" value="${el.top}" step="0.5" min="0"
                           onchange="window.elementEditor.updateField('top', parseFloat(this.value))">
                </div>
                <div class="prop-group">
                    <label>Left (mm)</label>
                    <input class="prop-control" type="number" value="${el.left}" step="0.5" min="0"
                           onchange="window.elementEditor.updateField('left', parseFloat(this.value))">
                </div>
            </div>
            <div class="prop-row">
                <div class="prop-group">
                    <label>Width (mm)</label>
                    <input class="prop-control" type="number" value="${el.width}" step="0.5" min="1"
                           onchange="window.elementEditor.updateField('width', parseFloat(this.value))">
                </div>
                <div class="prop-group">
                    <label>Height (mm)</label>
                    <input class="prop-control" type="number" value="${el.height}" step="0.5" min="1"
                           onchange="window.elementEditor.updateField('height', parseFloat(this.value))">
                </div>
            </div>
        `;
    }

    renderStyleTab(el) {
        const def = window.ReportingEngine.state.definition;
        const ds = def.defaultStyle || {};
        const isInheriting = el.inheritStyle !== false;
        this.contentEl.innerHTML = `
            <div class="prop-row">
                <div class="prop-group">
                    <label>Font</label>
                    <select class="prop-control" onchange="window.elementEditor.updateField('fontFamily', this.value)">
                        ${renderFontOptions(el.fontFamily)}
                    </select>
                </div>
                <div class="prop-group">
                    <label>Size (pt)</label>
                    <input class="prop-control" type="number" value="${el.fontSize || 10}" min="6" max="72"
                           onchange="window.elementEditor.updateField('fontSize', parseInt(this.value))">
                </div>
            </div>
            <div class="prop-group">
                <label style="display:flex;gap:12px;font-weight:400;text-transform:none">
                    <label><input type="checkbox" ${el.bold ? 'checked' : ''} onchange="window.elementEditor.updateField('bold', this.checked)"> Bold</label>
                    <label><input type="checkbox" ${el.italic ? 'checked' : ''} onchange="window.elementEditor.updateField('italic', this.checked)"> Italic</label>
                    <label><input type="checkbox" ${el.underline ? 'checked' : ''} onchange="window.elementEditor.updateField('underline', this.checked)"> Underline</label>
                </label>
            </div>
            <div class="prop-row">
                <div class="prop-group">
                    <label>Color</label>
                    <input class="prop-control" type="color" value="${el.color || '#000000'}"
                           onchange="window.elementEditor.updateField('color', this.value)">
                </div>
                <div class="prop-group">
                    <label>Background</label>
                    <div style="display:flex;gap:4px;align-items:center">
                        <input class="prop-control" type="color" value="${el.backgroundColor && el.backgroundColor !== 'transparent' ? el.backgroundColor : '#ffffff'}"
                               onchange="window.elementEditor.updateField('backgroundColor', this.value === '#ffffff' ? 'transparent' : this.value)">
                        <button type="button" title="Transparent" onclick="window.elementEditor.updateField('backgroundColor', 'transparent')" style="width:24px;height:24px;padding:0;font-size:12px;line-height:1;border:1px solid #ccc;border-radius:3px;background:#fff;cursor:pointer">&times;</button>
                    </div>
                </div>
            </div>
            <div class="prop-row">
                <div class="prop-group">
                    <label>Text Align</label>
                    <select class="prop-control" onchange="window.elementEditor.updateField('textAlign', this.value)">
                        <option value="left" ${el.textAlign === 'left' ? 'selected' : ''}>Left</option>
                        <option value="center" ${el.textAlign === 'center' ? 'selected' : ''}>Center</option>
                        <option value="right" ${el.textAlign === 'right' ? 'selected' : ''}>Right</option>
                    </select>
                </div>
                <div class="prop-group">
                    <label>Vertical</label>
                    <select class="prop-control" onchange="window.elementEditor.updateField('verticalAlign', this.value)">
                        <option value="top" ${el.verticalAlign === 'top' ? 'selected' : ''}>Top</option>
                        <option value="middle" ${el.verticalAlign === 'middle' ? 'selected' : ''}>Middle</option>
                        <option value="bottom" ${el.verticalAlign === 'bottom' ? 'selected' : ''}>Bottom</option>
                    </select>
                </div>
            </div>
            ${!isInheriting ? `
            <div style="border-top:1px solid var(--color-border);padding-top:8px;margin-top:8px">
                <button class="btn btn-sm" onclick="window.elementEditor.resetToDefaultStyle()" style="color:var(--color-primary);width:100%">
                    <i class="ph-arrow-counter-clockwise"></i> Reset to Report Defaults
                </button>
            </div>` : ''}
        `;
    }

    renderAdvancedTab(el) {
        this.contentEl.innerHTML = `
            <div class="prop-group">
                <label>Word Wrap</label>
                <label style="font-weight:400;text-transform:none">
                    <input type="checkbox" ${el.wordWrap !== false ? 'checked' : ''}
                           onchange="window.elementEditor.updateField('wordWrap', this.checked)"> Enabled
                </label>
            </div>
            <div class="prop-group">
                <label>Conditional Expression (PHP)</label>
                <textarea class="prop-control" rows="3" style="font-family:var(--font-mono);font-size:12px"
                    onchange="window.elementEditor.updateField('conditionalExpression', this.value)"
                    placeholder="\$value > 100">${escapeHtml(el.conditionalExpression || '')}</textarea>
            </div>
            <div class="prop-group">
                <label>Conditional Style (JSON)</label>
                <textarea class="prop-control" rows="3" style="font-family:var(--font-mono);font-size:12px"
                    onchange="window.elementEditor.updateField('conditionalStyle', this.value)"
                    placeholder='{"color":"#ff0000","bold":true}'>${escapeHtml(el.conditionalStyle || '')}</textarea>
            </div>
        `;
    }

    renderBandGeneralTab(band) {
        let bandMinH = 1;
        if (band.elements) {
            for (const el of band.elements) {
                const bottom = (el.top || 0) + (el.height || 0);
                if (bottom > bandMinH) bandMinH = bottom;
            }
        }
        this.contentEl.innerHTML = `
            <div class="prop-group">
                <label>Type</label>
                <div style="font-size:13px;color:#64748b">${band.type}</div>
            </div>
            <div class="prop-group">
                <label>Height (mm)</label>
                <input class="prop-control" type="number" value="${band.height}" min="${bandMinH}" step="0.5"
                       onchange="window.bandManager.resizeBand('${band.type}', parseFloat(this.value))">
            </div>
            <div class="prop-group">
                <label>Background Color</label>
                <div style="display:flex;gap:4px;align-items:center">
                    <input class="prop-control" type="color" value="${band.backgroundColor && band.backgroundColor !== 'transparent' ? band.backgroundColor : '#ffffff'}"
                           onchange="window.elementEditor.updateBandField('backgroundColor', this.value === '#ffffff' ? 'transparent' : this.value)">
                    <button type="button" class="btn-icon" title="Transparent" onclick="window.elementEditor.updateBandField('backgroundColor', 'transparent')" style="width:24px;height:24px;padding:0;font-size:12px;line-height:1;border:1px solid #ccc;border-radius:3px;background:#fff;cursor:pointer">&times;</button>
                </div>
            </div>
            <div class="prop-group">
                <label style="font-weight:400;text-transform:none">
                    <input type="checkbox" ${band.printOnEveryPage ? 'checked' : ''}
                           onchange="window.elementEditor.updateBandField('printOnEveryPage', this.checked)"> Print on every page
                </label>
            </div>
            <div class="prop-group">
                <label style="font-weight:400;text-transform:none">
                    <input type="checkbox" ${band.keepTogether ? 'checked' : ''}
                           onchange="window.elementEditor.updateBandField('keepTogether', this.checked)"> Keep together
                </label>
            </div>
        `;
    }

    renderReportProps() {
        switch (this.activeTab) {
            case 'general': this.renderReportGeneralTab(); break;
            case 'style': this.renderReportStyleTab(); break;
            case 'advanced': this.renderReportAdvancedTab(); break;
            default:
                this.contentEl.innerHTML = '';
                break;
        }
    }

    renderReportGeneralTab() {
        const def = window.ReportingEngine.state.definition;
        const guid = def.guid;
        const extLink = guid ? `/api/render/${guid}` : null;
        this.contentEl.innerHTML = `
            <div class="prop-group">
                <label>Report ID (GUID)</label>
                <input class="prop-control" type="text" value="${def.guid || (window.ReportingEngine.state.activeReportId ? '—' : 'Not saved yet')}" readonly style="font-family:var(--font-mono, monospace);font-size:12px;cursor:default;user-select:all">
            </div>
            ${extLink ? `
            <div class="prop-group">
                <label>External Access Link</label>
                <div style="display:flex;gap:6px;align-items:stretch">
                    <input class="prop-control" type="text" value="${window.location.origin}${extLink}" readonly style="flex:1;font-family:var(--font-mono,monospace);font-size:11px;cursor:default;user-select:all">
                    <button class="btn btn-sm" onclick="var b=this;navigator.clipboard.writeText('${window.location.origin}${extLink}').then(function(){b.textContent='Copied!';setTimeout(function(){b.textContent='Copy'},1500)}).catch(function(){})" style="flex-shrink:0">Copy</button>
                    <a href="${extLink}" target="_blank" class="btn btn-sm btn-primary" style="flex-shrink:0;text-decoration:none">Open</a>
                </div>
            </div>` : ''}
            <div class="prop-group">
                <label>Report Name</label>
                <input class="prop-control" type="text" value="${escapeHtml(def.name || '')}"
                       onchange="window.elementEditor.updateReportField('name', this.value || 'Untitled Report')">
            </div>
            <div class="prop-group">
                <label>Description</label>
                <textarea class="prop-control" rows="2" style="resize:vertical"
                    onchange="window.elementEditor.updateReportField('description', this.value)">${escapeHtml(def.description || '')}</textarea>
            </div>
        `;
    }

    renderReportStyleTab() {
        const def = window.ReportingEngine.state.definition;
        const ds = def.defaultStyle || {};
        this.contentEl.innerHTML = `
            <div class="prop-row">
                <div class="prop-group">
                    <label>Font</label>
                    <select class="prop-control" onchange="window.elementEditor.updateDefaultStyle('fontFamily', this.value)">
                        ${renderFontOptions(ds.fontFamily || 'Arial')}
                    </select>
                </div>
                <div class="prop-group">
                    <label>Size (pt)</label>
                    <input class="prop-control" type="number" value="${ds.fontSize || 10}" min="6" max="72"
                           onchange="window.elementEditor.updateDefaultStyle('fontSize', parseInt(this.value) || 10)">
                </div>
            </div>
            <div class="prop-row">
                <div class="prop-group">
                    <label>Color</label>
                    <input class="prop-control" type="color" value="${ds.color || '#000000'}"
                           onchange="window.elementEditor.updateDefaultStyle('color', this.value)">
                </div>
                <div class="prop-group">
                    <label>Background</label>
                    <div style="display:flex;gap:4px;align-items:center">
                        <input class="prop-control" type="color" value="${ds.backgroundColor && ds.backgroundColor !== 'transparent' ? ds.backgroundColor : '#ffffff'}"
                               onchange="window.elementEditor.updateDefaultStyle('backgroundColor', this.value === '#ffffff' ? 'transparent' : this.value)">
                        <button type="button" class="btn-icon" title="Transparent" onclick="window.elementEditor.updateDefaultStyle('backgroundColor', 'transparent')" style="width:24px;height:24px;padding:0;font-size:12px;line-height:1;border:1px solid #ccc;border-radius:3px;background:#fff;cursor:pointer">&times;</button>
                    </div>
                </div>
            </div>
        `;
    }

    renderReportAdvancedTab() {
        const def = window.ReportingEngine.state.definition;
        const page = def.page || {};
        this.contentEl.innerHTML = `
            <div class="prop-group">
                <label style="font-weight:600;font-size:11px;text-transform:uppercase">Page Setup</label>
            </div>
            <div class="prop-row">
                <div class="prop-group">
                    <label>Paper Size</label>
                    <select class="prop-control" onchange="window.elementEditor.updatePageSetting('paperSize', this.value)">
                        <option value="A4" ${(page.paperSize||'A4') === 'A4' ? 'selected' : ''}>A4 (210 × 297 mm)</option>
                        <option value="Letter" ${(page.paperSize||'A4') === 'Letter' ? 'selected' : ''}>Letter (216 × 279 mm)</option>
                        <option value="Legal" ${(page.paperSize||'A4') === 'Legal' ? 'selected' : ''}>Legal (216 × 356 mm)</option>
                    </select>
                </div>
                <div class="prop-group">
                    <label>Orientation</label>
                    <select class="prop-control" onchange="window.elementEditor.updatePageSetting('orientation', this.value)">
                        <option value="portrait" ${(page.orientation||'portrait') === 'portrait' ? 'selected' : ''}>Portrait</option>
                        <option value="landscape" ${(page.orientation||'portrait') === 'landscape' ? 'selected' : ''}>Landscape</option>
                    </select>
                </div>
            </div>
            <div class="prop-row">
                <div class="prop-group">
                    <label>Top (mm)</label>
                    <input class="prop-control" type="number" value="${page.marginTop ?? 20}" min="0" step="1"
                           onchange="window.elementEditor.updatePageSetting('marginTop', parseFloat(this.value) || 0)">
                </div>
                <div class="prop-group">
                    <label>Bottom (mm)</label>
                    <input class="prop-control" type="number" value="${page.marginBottom ?? 20}" min="0" step="1"
                           onchange="window.elementEditor.updatePageSetting('marginBottom', parseFloat(this.value) || 0)">
                </div>
            </div>
            <div class="prop-row">
                <div class="prop-group">
                    <label>Left (mm)</label>
                    <input class="prop-control" type="number" value="${page.marginLeft ?? 15}" min="0" step="1"
                           onchange="window.elementEditor.updatePageSetting('marginLeft', parseFloat(this.value) || 0)">
                </div>
                <div class="prop-group">
                    <label>Right (mm)</label>
                    <input class="prop-control" type="number" value="${page.marginRight ?? 15}" min="0" step="1"
                           onchange="window.elementEditor.updatePageSetting('marginRight', parseFloat(this.value) || 0)">
                </div>
            </div>
            <div class="prop-group" style="border-top:1px solid var(--color-border);padding-top:12px;margin-top:8px">
                <label style="font-weight:400;text-transform:none">
                    <input type="checkbox" ${def.showGrid ? 'checked' : ''}
                           onchange="window.elementEditor.updateReportField('showGrid', this.checked)">
                    Show Grid
                </label>
            </div>
            <div class="prop-group">
                <label style="font-weight:400;text-transform:none">
                    <input type="checkbox" ${def.snapToGrid !== false ? 'checked' : ''}
                           onchange="window.elementEditor.updateReportField('snapToGrid', this.checked)">
                    Snap to Grid
                </label>
            </div>
            <div class="prop-group">
                <label>Grid Size (mm)</label>
                <input class="prop-control" type="number" value="${def.gridSize || 2}" min="1" max="20" step="1"
                       onchange="window.elementEditor.updateReportField('gridSize', parseInt(this.value) || 2)">
            </div>
        `;
    }

    updateReportField(field, value) {
        const def = window.ReportingEngine.state.definition;
        def[field] = value;
        window.ReportingEngine.dispatch('SET_DIRTY', true);
        this.designer.renderCanvas();
        this.renderReportProps();
    }

    updatePageSetting(field, value) {
        const def = window.ReportingEngine.state.definition;
        if (!def.page) def.page = {};
        def.page[field] = value;
        window.ReportingEngine.dispatch('SET_DIRTY', true);
        this.designer.renderCanvas();
        this.renderReportProps();
    }

    updateDefaultStyle(field, value) {
        const def = window.ReportingEngine.state.definition;
        if (!def.defaultStyle) def.defaultStyle = {};
        const oldVal = def.defaultStyle[field];
        def.defaultStyle[field] = value;
        if (oldVal !== undefined && oldVal !== value) {
            this.designer.applyDefaultStyleToElements(field, oldVal, value);
        }
        window.ReportingEngine.dispatch('SET_DIRTY', true);
        this.designer.renderCanvas();
        this.renderReportProps();
    }

    updateField(field, value) {
        if (!this.currentElement) return;
        const el = this.currentElement;
        // Snap position/size fields
        if (['top', 'left', 'width', 'height'].includes(field)) {
            value = this.designer.snapValue(parseFloat(value) || 0);
            if (field === 'left' || field === 'top') value = Math.max(0, value);
            if (field === 'width' || field === 'height') value = Math.max(1, value);
        }
        el[field] = value;

        // Expand band when element top/height exceeds it
        if ((field === 'top' || field === 'height') && this.currentBand) {
            const bottom = (el.top || 0) + (el.height || 0);
            if (bottom > this.currentBand.height) {
                this.currentBand.height = this.designer.snapValue(bottom);
            }
        }

        // If a style field is manually changed, mark element as no longer inheriting
        if (['fontFamily', 'fontSize', 'color', 'backgroundColor'].includes(field)) {
            el.inheritStyle = false;
        }
        this.designer.renderCanvas();
        if (window.ReportingEngine.state.selectedElement === el.id) {
            this.designer.selectElement(el.id);
        }
        this.render();
        window.ReportingEngine.dispatch('SET_DIRTY', true);
    }

    resetToDefaultStyle() {
        if (!this.currentElement) return;
        const def = window.ReportingEngine.state.definition;
        const ds = def.defaultStyle || {};
        const el = this.currentElement;
        el.fontFamily = ds.fontFamily || 'Arial';
        el.fontSize = ds.fontSize || 10;
        el.color = ds.color || '#000000';
        el.backgroundColor = ds.backgroundColor || 'transparent';
        el.inheritStyle = true;
        this.designer.renderCanvas();
        this.render();
        window.ReportingEngine.dispatch('SET_DIRTY', true);
    }

    updateBandField(field, value) {
        if (!this.currentBand) return;
        this.currentBand[field] = value;
        this.designer.renderCanvas();
        window.ReportingEngine.dispatch('SET_DIRTY', true);
    }
}
