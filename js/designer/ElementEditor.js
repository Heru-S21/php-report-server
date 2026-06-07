class ElementEditor {
    constructor(designer) {
        this.designer = designer;
        this.contentEl = document.getElementById('properties-content');
        this.currentElement = null;
        this.currentBand = null;
        this.activeTab = 'general';
        this.borderEditor = new BorderEditor(designer);
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
            ${el.type === 'image' ? `
            <div class="prop-group">
                <label>Image URL</label>
                <input class="prop-control" type="text" value="${escapeHtml(el.imageUrl || '')}"
                       onchange="window.elementEditor.updateField('imageUrl', this.value)">
            </div>` : ''}
            <div class="prop-row">
                <div class="prop-group">
                    <label>Top (mm)</label>
                    <input class="prop-control" type="number" value="${el.top}" step="0.5"
                           onchange="window.elementEditor.updateField('top', parseFloat(this.value))">
                </div>
                <div class="prop-group">
                    <label>Left (mm)</label>
                    <input class="prop-control" type="number" value="${el.left}" step="0.5"
                           onchange="window.elementEditor.updateField('left', parseFloat(this.value))">
                </div>
            </div>
            <div class="prop-row">
                <div class="prop-group">
                    <label>Width (mm)</label>
                    <input class="prop-control" type="number" value="${el.width}" step="0.5"
                           onchange="window.elementEditor.updateField('width', parseFloat(this.value))">
                </div>
                <div class="prop-group">
                    <label>Height (mm)</label>
                    <input class="prop-control" type="number" value="${el.height}" step="0.5"
                           onchange="window.elementEditor.updateField('height', parseFloat(this.value))">
                </div>
            </div>
        `;
    }

    renderStyleTab(el) {
        this.contentEl.innerHTML = `
            <div class="prop-row">
                <div class="prop-group">
                    <label>Font</label>
                    <select class="prop-control" onchange="window.elementEditor.updateField('fontFamily', this.value)">
                        <option value="Arial" ${el.fontFamily === 'Arial' ? 'selected' : ''}>Arial</option>
                        <option value="Helvetica" ${el.fontFamily === 'Helvetica' ? 'selected' : ''}>Helvetica</option>
                        <option value="Times New Roman" ${el.fontFamily === 'Times New Roman' ? 'selected' : ''}>Times New Roman</option>
                        <option value="Courier New" ${el.fontFamily === 'Courier New' ? 'selected' : ''}>Courier New</option>
                        <option value="Georgia" ${el.fontFamily === 'Georgia' ? 'selected' : ''}>Georgia</option>
                        <option value="Verdana" ${el.fontFamily === 'Verdana' ? 'selected' : ''}>Verdana</option>
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
                    <input class="prop-control" type="color" value="${el.backgroundColor || '#ffffff'}"
                           onchange="window.elementEditor.updateField('backgroundColor', this.value === '#ffffff' ? 'transparent' : this.value)">
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
        this.contentEl.innerHTML = `
            <div class="prop-group">
                <label>Type</label>
                <div style="font-size:13px;color:#64748b">${band.type}</div>
            </div>
            <div class="prop-group">
                <label>Height (mm)</label>
                <input class="prop-control" type="number" value="${band.height}" min="4" step="0.5"
                       onchange="window.bandManager.resizeBand('${band.type}', parseFloat(this.value))">
            </div>
            <div class="prop-group">
                <label>Background Color</label>
                <input class="prop-control" type="color" value="${band.backgroundColor && band.backgroundColor !== 'transparent' ? band.backgroundColor : '#ffffff'}"
                       onchange="window.elementEditor.updateBandField('backgroundColor', this.value === '#ffffff' ? 'transparent' : this.value)">
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
        const def = window.ReportingEngine.state.definition;
        this.contentEl.innerHTML = `
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

    updateField(field, value) {
        if (!this.currentElement) return;
        const el = this.currentElement;
        // Snap position/size fields
        if (['top', 'left', 'width', 'height'].includes(field)) {
            value = this.designer.snapValue(parseFloat(value) || 0);
            if (field === 'width' || field === 'height') value = Math.max(1, value);
        }
        el[field] = value;
        this.designer.renderCanvas();
        if (window.ReportingEngine.state.selectedElement === el.id) {
            this.designer.selectElement(el.id);
        }
    }

    updateBandField(field, value) {
        if (!this.currentBand) return;
        this.currentBand[field] = value;
        this.designer.renderCanvas();
    }
}
