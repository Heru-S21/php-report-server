class Designer {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        this.canvasInner = document.getElementById('canvas-inner');
        this.reportId = window.ReportingEngine.state.activeReportId;
        this.elements = {};
        this.bands = [];
        this.zoom = 1;
        this.init();
    }

    async init() {
        if (this.reportId) {
            await this.loadReport(this.reportId);
        } else {
            this.createDefaultDefinition();
        }
        this.renderCanvas();
        this.renderObjectTree();
        this.bindEvents();
        this.selectReport();
    }

    async loadReport(id) {
        try {
            const res = await window.ReportingEngine.api('GET', `/api/reports/${id}`);
            if (res.data) {
                const def = typeof res.data.definition === 'string'
                    ? JSON.parse(res.data.definition) : res.data.definition;
                // Ensure grid defaults for backward compatibility
                if (def.showGrid === undefined) def.showGrid = true;
                if (def.snapToGrid === undefined) def.snapToGrid = true;
                if (!def.gridSize) def.gridSize = 2;
                window.ReportingEngine.dispatch('SET_DEFINITION', def);
                this.bands = def.bands || this.getDefaultBands();
                window.ReportingEngine.state.queryColumns = def.queryColumns || [];
            }
        } catch (e) {
            console.error('Failed to load report:', e);
            this.createDefaultDefinition();
        }
    }

    createDefaultDefinition() {
        const def = {
            version: '1.0',
            name: 'Untitled Report',
            description: '',
            connectionId: null,
            page: {
                paperSize: 'A4',
                orientation: 'portrait',
                marginTop: 20,
                marginBottom: 20,
                marginLeft: 15,
                marginRight: 15,
            },
            query: { sql: '', visualJson: null, parameters: [] },
            groups: [],
            bands: this.getDefaultBands(),
            showGrid: true,
            snapToGrid: true,
            gridSize: 2,
        };
        window.ReportingEngine.dispatch('SET_DEFINITION', def);
        this.bands = def.bands;
    }

    getDefaultBands() {
        return [
            { type: 'page_header', height: 30, printOnEveryPage: true, backgroundColor: '#e8f4f8', border: {}, elements: [] },
            { type: 'report_header', height: 20, backgroundColor: '#e8f0fe', border: {}, elements: [] },
            { type: 'detail', height: 16, backgroundColor: '#f0fdf4', border: {}, elements: [] },
            { type: 'report_footer', height: 22, backgroundColor: '#e8f0fe', border: {}, elements: [] },
            { type: 'page_footer', height: 16, printOnEveryPage: true, backgroundColor: '#e8f4f8', border: {}, elements: [] },
        ];
    }

    renderCanvas() {
        const page = window.ReportingEngine.state.definition.page || {};
        const paperWidth = page.paperSize === 'A4' ? 210 : page.paperSize === 'Letter' ? 215.9 : 210;
        const usableWidth = paperWidth - (page.marginLeft || 15) - (page.marginRight || 15);
        const widthMm = page.orientation === 'landscape' ? (paperWidth * 1.414) : paperWidth;
        const usableHeight = widthMm - (page.marginTop || 20) - (page.marginBottom || 20);

        // Store usableWidth so drop handler can use it
        this.canvasUsableWidth = usableWidth;

        this.canvasInner.style.width = usableWidth + 'mm';
        this.canvasInner.style.transform = `scale(${this.zoom})`;
        this.canvasInner.style.transformOrigin = 'top center';

        let html = '';
        const bandOrder = ['page_header', 'report_header', 'group_header', 'detail', 'group_footer', 'report_footer', 'page_footer'];

        const sortedBands = [...this.bands].sort((a, b) => {
            return bandOrder.indexOf(a.type) - bandOrder.indexOf(b.type);
        });

        for (const band of sortedBands) {
            html += this.renderBand(band);
        }

        // Grid overlay (on top of bands so dots are always visible)
        const def = window.ReportingEngine.state.definition;
        if (def.showGrid) {
            const gs = def.gridSize || 2;
            html += `<div class="grid-overlay" style="background-image:radial-gradient(circle, #bbb 0.8px, transparent 0.8px);background-size:${gs}mm ${gs}mm"></div>`;
        }

        this.canvasInner.innerHTML = html;
        this.attachElementEvents();
    }

    renderBand(band) {
        const borderStyle = band.border ? this.borderToStyle(band.border) : '';
        const isGroup = band.type === 'group_header' || band.type === 'group_footer';
        const label = isGroup && band.groupField
            ? `${band.type.replace('_', ' ')} [${band.groupField}]`
            : band.type.replace('_', ' ');

        return `
            <div class="band band-${band.type} ${window.ReportingEngine.state.selectedBand === band.type ? 'selected' : ''} drop-zone"
                 data-band-type="${band.type}"
                 style="height:${band.height}mm; background:${band.backgroundColor || 'transparent'}; ${borderStyle}">
                <span class="band-label">${label}</span>
                ${band.elements ? band.elements.map(el => this.renderElement(el)).join('') : ''}
                <div class="band-resize-handle" data-band-type="${band.type}"></div>
            </div>
        `;
    }

    renderElement(el) {
        if (!el) return '';
        const isSelected = window.ReportingEngine.state.selectedElement === el.id;
        const content = this.getElementContent(el);
        const style = `
            top: ${el.top}mm;
            left: ${el.left}mm;
            width: ${el.width}mm;
            height: ${el.height}mm;
            font-family: ${el.fontFamily || 'Arial'};
            font-size: ${el.fontSize || 10}pt;
            font-weight: ${el.bold ? 'bold' : 'normal'};
            font-style: ${el.italic ? 'italic' : 'normal'};
            text-decoration: ${el.underline ? 'underline' : 'none'};
            color: ${el.color || '#000000'};
            text-align: ${el.textAlign || 'left'};
            background-color: ${el.backgroundColor || 'transparent'};
            ${el.border ? this.borderToStyle(el.border) : ''}
        `;

        return `
            <div class="canvas-element ${isSelected ? 'selected' : ''}"
                 data-element-id="${el.id}"
                 data-element-type="${el.type}"
                 style="${style}">
                ${content}
                <div class="resize-handle" data-element-id="${el.id}"></div>
            </div>
        `;
    }

    getElementContent(el) {
        switch (el.type) {
            case 'label': return el.text || 'Label';
            case 'field': return el.fieldName ? `[${el.fieldName}]` : '[Field]';
            case 'aggregate': {
                const func = (el.aggregateFunc || 'SUM').toUpperCase();
                return el.fieldName ? `{${func}(${el.fieldName})}` : '{AGGREGATE}';
            }
            case 'image': return el.imageUrl ? `<img src="${el.imageUrl}" style="max-width:100%;max-height:100%">` : '[Image]';
            case 'line': return '<hr style="border:none;border-top:1px solid #000;margin:0">';
            case 'rect': return '';
            case 'pageno': return el.text || 'Page {page} of {pages}';
            case 'datetime': return '[Date/Time]';
            default: return el.text || '';
        }
    }

    borderToStyle(border) {
        if (!border) return '';
        const sides = ['top', 'right', 'bottom', 'left'];
        let styles = [];
        for (const side of sides) {
            const s = border[side];
            if (s && s.enabled) {
                styles.push(`border-${side}: ${s.width || 1}px ${s.style || 'solid'} ${s.color || '#000'}`);
            }
        }
        return styles.join('; ');
    }

    bindEvents() {
        document.addEventListener('click', (e) => {
            // Ignore clicks in panels (left toolbox, right properties/tree)
            if (e.target.closest('.panel-left') || e.target.closest('.panel-right')) return;
            const el = e.target.closest('.canvas-element');
            if (el) {
                this.selectElement(el.dataset.elementId);
                return;
            }
            const band = e.target.closest('.band');
            if (band) {
                this.selectBand(band.dataset.bandType);
                return;
            }
            this.selectReport();
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey && e.key === 'z') { e.preventDefault(); this.undo(); }
            if (e.ctrlKey && (e.key === 'y' || (e.shiftKey && e.key === 'z'))) { e.preventDefault(); this.redo(); }
            if ((e.key === 'Delete' || e.key === 'Backspace') && window.ReportingEngine.state.selectedElement) {
                e.preventDefault();
                this.removeElement(window.ReportingEngine.state.selectedElement);
            }
            if (['ArrowUp','ArrowDown','ArrowLeft','ArrowRight'].includes(e.key) && window.ReportingEngine.state.selectedElement) {
                e.preventDefault();
                const step = e.shiftKey ? 10 : 1;
                const dx = e.key === 'ArrowLeft' ? -step : e.key === 'ArrowRight' ? step : 0;
                const dy = e.key === 'ArrowUp' ? -step : e.key === 'ArrowDown' ? step : 0;
                this.moveElement(window.ReportingEngine.state.selectedElement, dx, dy);
            }
            if (e.ctrlKey && e.key === 's') { e.preventDefault(); this.save(); }
            if (e.ctrlKey && e.key === 'p') { e.preventDefault(); this.preview(); }
            if (e.key === 'Escape') { this.deselectAll(); }
        });

        // Toolbox drag
        document.querySelectorAll('.toolbox-item').forEach(item => {
            item.addEventListener('dragstart', (e) => {
                e.dataTransfer.setData('text/plain', item.dataset.type);
                e.dataTransfer.effectAllowed = 'copy';
            });
        });

        // Drag-over: highlight target band and allow drop
        this.canvasInner.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
            const bandEl = this.findBandAtPoint(e.clientX, e.clientY);
            document.querySelectorAll('.band.drag-over').forEach(b => b.classList.remove('drag-over'));
            if (bandEl) bandEl.classList.add('drag-over');
        });

        this.canvasInner.addEventListener('dragleave', (e) => {
            document.querySelectorAll('.band.drag-over').forEach(b => b.classList.remove('drag-over'));
        });

        // Canvas drop — uses coordinate-based band detection
        this.canvasInner.addEventListener('drop', (e) => {
            e.preventDefault();
            document.querySelectorAll('.band.drag-over').forEach(b => b.classList.remove('drag-over'));
            const type = e.dataTransfer.getData('text/plain');
            if (!type) return;
            const bandEl = this.findBandAtPoint(e.clientX, e.clientY);
            if (!bandEl) return;
            const bandType = bandEl.dataset.bandType;
            const bandData = this.bands.find(b => b.type === bandType);
            if (!bandData) return;

            // Convert visual pixels to mm using the canvas-to-mm ratio
            const bandRect = bandEl.getBoundingClientRect();
            const canvasRect = this.canvasInner.getBoundingClientRect();
            // Use Y ratio from band itself (band height in mm / visual height in pixels)
            // Use X ratio from canvas (usableWidth in mm / visual width in pixels)
            const mmPerPxY = bandData.height / bandRect.height;
            const mmPerPxX = this.canvasUsableWidth / canvasRect.width;
            const x_mm = (e.clientX - bandRect.left) * mmPerPxX;
            const y_mm = (e.clientY - bandRect.top) * mmPerPxY;

            const fieldName = e.dataTransfer.getData('field-name') || null;
            this.addElement(type, bandType, this.snapValue(x_mm), this.snapValue(y_mm), fieldName);
        });
    }

    // Find band DOM element at given viewport coordinates
    findBandAtPoint(clientX, clientY) {
        const bands = this.canvasInner.querySelectorAll('.band');
        for (const b of bands) {
            const r = b.getBoundingClientRect();
            if (clientY >= r.top && clientY <= r.bottom) {
                return b;
            }
        }
        return null;
    }

    attachElementEvents() {
        const getPxToMm = () => {
            const cr = this.canvasInner.getBoundingClientRect();
            return this.canvasUsableWidth / cr.width;
        };

        document.querySelectorAll('.canvas-element').forEach(el => {
            el.addEventListener('mousedown', (e) => {
                if (e.target.closest('.resize-handle')) return;
                const startX = e.clientX;
                const startY = e.clientY;
                const elId = el.dataset.elementId;
                const origTop = parseFloat(el.style.top);
                const origLeft = parseFloat(el.style.left);
                const pxToMm = getPxToMm();

                const onMove = (me) => {
                    const dx = (me.clientX - startX) * pxToMm;
                    const dy = (me.clientY - startY) * pxToMm;
                    el.style.left = this.snapValue(origLeft + dx) + 'mm';
                    el.style.top = this.snapValue(origTop + dy) + 'mm';
                };

                const onUp = () => {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    // Update definition
                    const band = this.findBandForElement(elId);
                    if (band) {
                        const elem = band.elements.find(e => e.id === elId);
                        if (elem) {
                            elem.top = parseFloat(el.style.top);
                            elem.left = parseFloat(el.style.left);
                        }
                    }
                };

                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
            });
        });

        // Resize handles
        document.querySelectorAll('.resize-handle').forEach(handle => {
            handle.addEventListener('mousedown', (e) => {
                e.stopPropagation();
                const el = handle.closest('.canvas-element');
                const elId = el.dataset.elementId;
                const startX = e.clientX;
                const startY = e.clientY;
                const origW = parseFloat(el.style.width);
                const origH = parseFloat(el.style.height);
                const pxToMm = getPxToMm();

                const onMove = (me) => {
                    const dw = (me.clientX - startX) * pxToMm;
                    const dh = (me.clientY - startY) * pxToMm;
                    el.style.width = Math.max(1, this.snapValue(origW + dw)) + 'mm';
                    el.style.height = Math.max(1, this.snapValue(origH + dh)) + 'mm';
                };

                const onUp = () => {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    const band = this.findBandForElement(elId);
                    if (band) {
                        const elem = band.elements.find(e => e.id === elId);
                        if (elem) {
                            elem.width = parseFloat(el.style.width);
                            elem.height = parseFloat(el.style.height);
                        }
                    }
                };

                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
            });
        });

        // Band resize
        document.querySelectorAll('.band-resize-handle').forEach(handle => {
            handle.addEventListener('mousedown', (e) => {
                e.stopPropagation();
                const band = handle.closest('.band');
                const bandType = band.dataset.bandType;
                const startY = e.clientY;
                const origH = parseFloat(band.style.height);
                const pxToMm = getPxToMm();

                const onMove = (me) => {
                    const dh = (me.clientY - startY) * pxToMm;
                    band.style.height = Math.max(1, this.snapValue(origH + dh)) + 'mm';
                };

                const onUp = () => {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    const b = this.bands.find(b => b.type === bandType);
                    if (b) b.height = parseFloat(band.style.height);
                };

                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
            });
        });
    }

    findBandForElement(elementId) {
        return this.bands.find(b => b.elements && b.elements.some(e => e.id === elementId));
    }

    selectElement(id) {
        window.ReportingEngine.dispatch('SELECT_ELEMENT', id);
        window.ReportingEngine.dispatch('SELECT_BAND', null);
        document.querySelectorAll('.canvas-element').forEach(el => {
            el.classList.toggle('selected', el.dataset.elementId === id);
        });
        document.querySelectorAll('.band').forEach(b => b.classList.remove('selected'));
        if (window.elementEditor) {
            const band = this.findBandForElement(id);
            if (band) {
                const elem = band.elements.find(e => e.id === id);
                if (elem) window.elementEditor.loadElement(elem);
            }
        }
        this.renderObjectTree();
    }

    selectBand(type) {
        window.ReportingEngine.dispatch('SELECT_ELEMENT', null);
        window.ReportingEngine.dispatch('SELECT_BAND', type);
        document.querySelectorAll('.band').forEach(b => {
            b.classList.toggle('selected', b.dataset.bandType === type);
        });
        document.querySelectorAll('.canvas-element').forEach(el => el.classList.remove('selected'));
        if (window.elementEditor) {
            const band = this.bands.find(b => b.type === type);
            if (band) window.elementEditor.loadBand(band);
        }
        this.renderObjectTree();
    }

    selectReport() {
        window.ReportingEngine.dispatch('SELECT_ELEMENT', null);
        window.ReportingEngine.dispatch('SELECT_BAND', null);
        document.querySelectorAll('.canvas-element, .band').forEach(el => el.classList.remove('selected'));
        if (window.elementEditor) {
            window.elementEditor.loadReport();
        }
        this.renderObjectTree();
    }

    snapValue(val) {
        const def = window.ReportingEngine.state.definition;
        if (!def.snapToGrid) return val;
        const grid = def.gridSize || 2;
        return Math.max(1, Math.round(val / grid)) * grid;
    }

    addElement(type, bandType, x, y, fieldName) {
        const band = this.bands.find(b => b.type === bandType);
        if (!band) return;

        if (!band.elements) band.elements = [];

        const el = {
            id: 'el-' + Date.now() + '-' + Math.random().toString(36).substr(2, 4),
            type: type,
            top: y,
            left: x,
            width: type === 'line' ? 80 : 40,
            height: type === 'line' ? 1 : 12,
            text: type === 'label' ? 'Label' : null,
            fieldName: type === 'field' ? (fieldName || '') : null,
            fontFamily: 'Arial',
            fontSize: 10,
            bold: false,
            italic: false,
            underline: false,
            color: '#000000',
            textAlign: 'left',
            backgroundColor: 'transparent',
            border: {},
        };

        if (type === 'aggregate') {
            el.aggregateFunc = 'sum';
            el.aggregateScope = bandType === 'group_footer' ? 'group' : 'report';
            el.format = '#,##0.00';
        }

        band.elements.push(el);
        this.renderCanvas();
        this.renderObjectTree();

        // Open aggregate editor for aggregate elements
        if (type === 'aggregate' && window.aggregateEditor) {
            window.aggregateEditor.open(el.id);
        }
    }

    removeElement(id) {
        for (const band of this.bands) {
            if (band.elements) {
                const idx = band.elements.findIndex(e => e.id === id);
                if (idx !== -1) {
                    band.elements.splice(idx, 1);
                    break;
                }
            }
        }
        window.ReportingEngine.dispatch('SELECT_ELEMENT', null);
        this.renderCanvas();
        this.renderObjectTree();
    }

    moveElement(id, dx, dy) {
        const band = this.findBandForElement(id);
        if (!band) return;
        const elem = band.elements.find(e => e.id === id);
        if (!elem) return;
        elem.top = Math.max(0, this.snapValue(elem.top + dy));
        elem.left = Math.max(0, this.snapValue(elem.left + dx));
        this.renderCanvas();
        if (window.ReportingEngine.state.selectedElement === id) {
            this.selectElement(id);
        }
    }

    setZoom(zoom) {
        this.zoom = zoom;
        window.ReportingEngine.dispatch('SET_ZOOM', zoom);
        this.renderCanvas();
    }

    pushUndoState() {
        const stack = window.ReportingEngine.state.undoStack;
        stack.push(JSON.stringify(window.ReportingEngine.state.definition));
        if (stack.length > 50) stack.shift();
        window.ReportingEngine.dispatch('UNDO_STACK', stack);
        window.ReportingEngine.dispatch('REDO_STACK', []);
    }

    async save() {
        this.pushUndoState();
        const def = window.ReportingEngine.state.definition;
        def.bands = this.bands;

        const payload = {
            name: def.name || 'Untitled Report',
            description: def.description || '',
            connection_id: def.connectionId,
            definition: JSON.stringify(def),
        };

        try {
            let res;
            if (this.reportId) {
                res = await window.ReportingEngine.api('PUT', `/api/reports/${this.reportId}`, payload);
            } else {
                res = await window.ReportingEngine.api('POST', '/api/reports', payload);
                if (res.data && res.data.id) {
                    this.reportId = res.data.id;
                    window.ReportingEngine.state.activeReportId = this.reportId;
                    history.replaceState(null, '', `/reports/designer/${this.reportId}`);
                }
            }
            window.ReportingEngine.dispatch('SET_DIRTY', false);
            if (res.success) {
                this.showToast('Report saved successfully');
            }
        } catch (e) {
            this.showToast('Failed to save report', 'error');
        }
    }

    preview() {
        window.location.href = `/reports/preview/${this.reportId || ''}`;
    }

    exportPdf() {
        if (!this.reportId) { this.showToast('Save the report first', 'error'); return; }
        window.open(`/api/render/${this.reportId}?format=pdf`, '_blank');
    }

    exportHtml() {
        if (!this.reportId) { this.showToast('Save the report first', 'error'); return; }
        window.open(`/api/render/${this.reportId}?format=html`, '_blank');
    }

    undo() {
        const stack = window.ReportingEngine.state.undoStack;
        if (stack.length === 0) return;
        const current = JSON.stringify(window.ReportingEngine.state.definition);
        window.ReportingEngine.state.redoStack.push(current);
        const prev = JSON.parse(stack.pop());
        window.ReportingEngine.dispatch('UNDO_STACK', stack);
        window.ReportingEngine.dispatch('SET_DEFINITION', prev);
        this.bands = prev.bands || this.getDefaultBands();
        this.renderCanvas();
        this.renderObjectTree();
    }

    redo() {
        const stack = window.ReportingEngine.state.redoStack;
        if (stack.length === 0) return;
        const current = JSON.stringify(window.ReportingEngine.state.definition);
        window.ReportingEngine.state.undoStack.push(current);
        const next = JSON.parse(stack.pop());
        window.ReportingEngine.dispatch('REDO_STACK', stack);
        window.ReportingEngine.dispatch('SET_DEFINITION', next);
        this.bands = next.bands || this.getDefaultBands();
        this.renderCanvas();
        this.renderObjectTree();
    }

    renderObjectTree() {
        const container = document.getElementById('object-tree');
        if (!container) return;
        const reportSelected = !window.ReportingEngine.state.selectedBand && !window.ReportingEngine.state.selectedElement;
        const bandOrder = ['page_header', 'report_header', 'group_header', 'detail', 'group_footer', 'report_footer', 'page_footer'];
        const sortedBands = [...this.bands].sort((a, b) => bandOrder.indexOf(a.type) - bandOrder.indexOf(b.type));

        let html = `<div class="tree-item report-tree-item ${reportSelected ? 'selected' : ''}"
                         data-tree-report="1">
                        <i class="ph-file-text"></i>
                        <span>Report</span>
                     </div>`;

        for (const band of sortedBands) {
            const isSelected = window.ReportingEngine.state.selectedBand === band.type;
            const label = band.type.replace(/_/g, ' ');
            const hasChildren = band.elements && band.elements.length > 0;

            html += `<div class="tree-item band-tree-item ${isSelected ? 'selected' : ''}"
                         data-tree-band="${band.type}">
                        <i class="ph-square"></i>
                        <span>${label}</span>
                        ${hasChildren ? `<span class="tree-badge">${band.elements.length}</span>` : ''}
                     </div>`;

            if (hasChildren) {
                for (const el of band.elements) {
                    const elSelected = window.ReportingEngine.state.selectedElement === el.id;
                    const elLabel = el.text || el.fieldName || el.type;
                    html += `<div class="tree-item element-tree-item ${elSelected ? 'selected' : ''}"
                                 data-tree-element="${el.id}"
                                 style="padding-left:40px">
                                <i class="ph-${el.type === 'label' ? 'text-t' : el.type === 'field' ? 'database' : el.type === 'aggregate' ? 'function' : 'square'}"></i>
                                <span>${elLabel}</span>
                                <small style="color:var(--color-text-muted)">${el.type}</small>
                             </div>`;
                }
            }
        }
        container.innerHTML = html;
        this.attachObjectTreeEvents(container);
    }

    attachObjectTreeEvents(container) {
        // Remove old listener to avoid duplicates
        if (this._treeClickHandler) {
            container.removeEventListener('click', this._treeClickHandler);
        }
        this._treeClickHandler = (e) => {
            const target = e.target.closest('[data-tree-band],[data-tree-element],[data-tree-report]');
            if (!target) return;
            e.stopPropagation();
            if (target.dataset.treeReport !== undefined) {
                this.selectReport();
                return;
            }
            const bandType = target.dataset.treeBand;
            const elementId = target.dataset.treeElement;
            if (elementId) {
                this.selectElement(elementId);
            } else if (bandType) {
                this.selectBand(bandType);
            }
        };
        container.addEventListener('click', this._treeClickHandler);
    }

    showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed; bottom: 24px; right: 24px;
            padding: 12px 20px; border-radius: 8px;
            background: ${type === 'success' ? '#16A34A' : '#DC2626'};
            color: white; font-size: 14px; font-weight: 500;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 9999;
            animation: slideIn 0.2s ease;
        `;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }
}
