class Designer {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        this.canvasInner = document.getElementById('canvas-inner');
        this.reportId = window.ReportingEngine.state.activeReportId;
        this.reportGuid = window.ReportingEngine.state.activeReportGuid || null;
        this.elements = {};
        this.bands = [];
        this.zoom = 1;
        this.selectedElementIds = [];
        this.init();
    }

    async init() {
        if (this.reportId) {
            await this.loadReport(this.reportId);
        } else {
            const stored = localStorage.getItem('designer_draft_new');
            if (stored) {
                const def = JSON.parse(stored);
                def.guid = null;
                this.bands = def.bands || this.getDefaultBands();
                window.ReportingEngine.dispatch('SET_DEFINITION', def);
            } else {
                this.createDefaultDefinition();
            }
        }
        this.renderCanvas();
        this.renderObjectTree();
        this.bindEvents();
        this.selectReport();
        if (window.groupEditor) window.groupEditor.updateGroupList();
        this.updateSaveIndicator();

        window.clipboard = { element: null, style: null };
        this.contextMenu = new ContextMenu(this);

        window.ReportingEngine.on('stateChange', (e) => {
            this.updateSaveIndicator();
            if (e.state.isDirty) this.autosave();
        });
    }

    storageKey() {
        const id = this.reportGuid || this.reportId;
        return id ? 'designer_draft_' + id : 'designer_draft_new';
    }

    autosave() {
        const key = this.storageKey();
        if (!key) return;
        const def = window.ReportingEngine.state.definition;
        def.bands = this.bands;
        localStorage.setItem(key, JSON.stringify(def));
        localStorage.setItem(key + '_dirty', window.ReportingEngine.state.isDirty ? '1' : '0');

        // Keep dirty flag in sync on the ID-based key (used after page reload)
        if (this.reportId && key !== 'designer_draft_' + this.reportId) {
            localStorage.setItem('designer_draft_' + this.reportId + '_dirty',
                window.ReportingEngine.state.isDirty ? '1' : '0');
        }
    }

    updateSaveIndicator() {
        const btn = document.getElementById('btn-save');
        if (!btn) return;
        const dot = btn.querySelector('.unsaved-dot');
        if (!dot) return;
        dot.style.display = window.ReportingEngine.state.isDirty ? 'inline' : 'none';
    }

    async loadReport(id) {
        this.resetHistory();
        try {
            const key = this.storageKey();
            const stored = key ? localStorage.getItem(key) : null;

            if (stored) {
                const def = JSON.parse(stored);
                def.guid = null;
                window.ReportingEngine.dispatch('LOAD_DEFINITION', def);
                this.bands = def.bands || this.getDefaultBands();
            }

            const res = await window.ReportingEngine.api('GET', `/api/reports/${id}`);
            if (res.data) {
                const def = typeof res.data.definition === 'string'
                    ? JSON.parse(res.data.definition) : res.data.definition;
                def.guid = res.data.guid || null;

                if (!stored) {
                    window.ReportingEngine.dispatch('LOAD_DEFINITION', def);
                    this.bands = def.bands || this.getDefaultBands();
                } else {
                    window.ReportingEngine.state.definition.guid = res.data.guid || null;
                }

                this.reportGuid = res.data.guid || null;
                window.ReportingEngine.state.queryColumns = def.queryColumns || [];
            }

            // Restore dirty state from localStorage so reloaded pages show unsaved indicator
            if (stored) {
                const wasDirty = localStorage.getItem(key + '_dirty') === '1';
                if (wasDirty) {
                    window.ReportingEngine.dispatch('SET_DIRTY', true);
                }
            }

            this.autosave();
        } catch (e) {
            console.error('Failed to load report:', e);
            if (!localStorage.getItem(this.storageKey())) {
                this.createDefaultDefinition();
            }
        }
    }

    createDefaultDefinition() {
        this.resetHistory();
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
            defaultStyle: {
                fontFamily: 'Arial',
                fontSize: 10,
                color: '#000000',
                backgroundColor: 'transparent',
            },
        };
        this.bands = def.bands;
        window.ReportingEngine.dispatch('SET_DEFINITION', def);
        this.autosave();
    }

    getDefaultBands() {
        return [
            { type: 'page_header', height: 30, printOnEveryPage: true, backgroundColor: 'transparent', border: {}, elements: [] },
            { type: 'report_header', height: 20, backgroundColor: 'transparent', border: {}, elements: [] },
            { type: 'column_header', height: 16, backgroundColor: 'transparent', border: {}, elements: [] },
            { type: 'detail', height: 16, backgroundColor: 'transparent', border: {}, elements: [] },
            { type: 'report_footer', height: 22, backgroundColor: 'transparent', border: {}, elements: [] },
            { type: 'page_footer', height: 16, printOnEveryPage: true, backgroundColor: 'transparent', border: {}, elements: [] },
        ];
    }

    renderCanvas() {
        const page = window.ReportingEngine.state.definition.page || {};
        const paperSizes = { A4: { w: 210, h: 297 }, Letter: { w: 215.9, h: 279.4 }, Legal: { w: 215.9, h: 355.6 } };
        const ps = paperSizes[page.paperSize] || paperSizes.A4;
        const paperW = page.orientation === 'landscape' ? ps.h : ps.w;
        const paperH = page.orientation === 'landscape' ? ps.w : ps.h;
        const usableWidth = paperW - (page.marginLeft || 15) - (page.marginRight || 15);

        // Store usableWidth so drop handler can use it
        this.canvasUsableWidth = usableWidth;

        this.canvasInner.style.width = usableWidth + 'mm';
        this.canvasInner.style.transform = `scale(${this.zoom})`;
        this.canvasInner.style.transformOrigin = 'top center';

        let html = '';
        const bandOrder = ['page_header', 'report_header', 'group_header', 'column_header', 'detail', 'group_footer', 'report_footer', 'page_footer'];

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
        const textAlign = el.textAlign || 'left';
        const vertAlign = el.verticalAlign || 'top';
        const flexAlign = vertAlign === 'middle' ? 'center' : vertAlign === 'bottom' ? 'flex-end' : null;
        const flexJustify = textAlign === 'center' ? 'center' : textAlign === 'right' ? 'flex-end' : 'flex-start';
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
            ${flexAlign ? `display:flex; align-items:${flexAlign}; justify-content:${flexJustify};` : `text-align:${textAlign};`}
            background-color: ${el.backgroundColor || 'transparent'};
            ${el.border ? this.borderToStyle(el.border) : ''}
        `;

        return `
            <div class="canvas-element ${isSelected ? 'selected' : ''} ${this.selectedElementIds.includes(el.id) && !isSelected ? 'multi-selected' : ''}"
                 data-element-id="${el.id}"
                 data-element-type="${el.type}"
                 style="${style}">
                <div class="corner-handle corner-tl"></div>
                <div class="corner-handle corner-tr"></div>
                <div class="corner-handle corner-bl"></div>
                <div class="corner-handle corner-br"></div>
                ${content}
                <div class="resize-handle" data-element-id="${el.id}"></div>
            </div>
        `;
    }

    getElementContent(el) {
        switch (el.type) {
            case 'label': return el.expression || el.text || 'Label';
            case 'field': return el.fieldName ? `[${el.fieldName}]` : '[Field]';
            case 'aggregate': {
                const func = (el.aggregateFunc || 'SUM').toUpperCase();
                return el.fieldName ? `{${func}(${el.fieldName})}` : '{AGGREGATE}';
            }
            case 'image': {
                const imgFit = el.imageDisplay === 'original' ? 'none' : el.imageDisplay === 'stretch' ? 'fill' : 'contain';
                return el.imageUrl ? `<img src="${el.imageUrl}" style="width:100%;height:100%;object-fit:${imgFit}">` : '[Image]';
            }
            case 'line': return '<hr style="border:none;border-top:1px solid #000;margin:0">';
            case 'rect': return '';
            case 'pageno': return el.text || '{PAGENO}';
            case 'pagecount': return el.text || '{PAGECOUNT}';
            case 'rowno': return el.text || '{ROWNO}';
            case 'datetime': return '[Date/Time]';
            case 'barcode': return el.barcodeExpression || el.text || '[Barcode]';
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
            // Ignore clicks in panels (left toolbox, right properties/tree) and modals
            if (e.target.closest('.panel-left') || e.target.closest('.panel-right') || e.target.closest('.modal')) return;
            const el = e.target.closest('.canvas-element');
            if (el) {
                if (e.ctrlKey || e.metaKey) {
                    const id = el.dataset.elementId;
                    const idx = this.selectedElementIds.indexOf(id);
                    if (idx >= 0) {
                        this.selectedElementIds.splice(idx, 1);
                    } else {
                        this.selectedElementIds.push(id);
                    }
                    this.selectElement(id);
                    this.updateAlignmentButtons();
                } else {
                    this.selectedElementIds = [];
                    this.selectElement(el.dataset.elementId);
                    this.updateAlignmentButtons();
                }
                return;
            }
            const band = e.target.closest('.band');
            if (band) {
                this.selectedElementIds = [];
                this.selectBand(band.dataset.bandType);
                this.updateAlignmentButtons();
                return;
            }
            this.selectedElementIds = [];
            this.selectReport();
            this.updateAlignmentButtons();
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Don't intercept when editing form controls
            const tag = e.target.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
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
            if (e.key === 'Escape') { this.selectedElementIds = []; this.deselectAll(); }
        });

        // Toolbox drag
        document.querySelectorAll('.toolbox-item').forEach(item => {
            item.addEventListener('dragstart', (e) => {
                const type = item.dataset.type;
                e.dataTransfer.setData('text/plain', type);
                e.dataTransfer.effectAllowed = 'copy';

                const defs = this.getElementDefaults(type);
                e.dataTransfer.setData('element-width', String(defs.width));
                e.dataTransfer.setData('element-height', String(defs.height));

                const dragEl = document.createElement('div');
                dragEl.textContent = type.charAt(0).toUpperCase() + type.slice(1);
                dragEl.style.cssText = `
                    width: ${Math.max(defs.width * 2, 60)}px;
                    height: ${Math.max(defs.height * 2, 24)}px;
                    background: ${type === 'image' ? '#e2e8f0' : type === 'line' ? '#94a3b8' : type === 'rect' ? '#f1f5f9' : '#fff'};
                    border: 2px solid ${type === 'line' ? 'none' : '#3b82f6'};
                    ${type === 'line' ? 'border-top: 3px solid #3b82f6;' : ''}
                    border-radius: 4px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font: bold 12px/1 Arial, sans-serif;
                    color: #1e293b;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
                `;
                document.body.appendChild(dragEl);
                e.dataTransfer.setDragImage(dragEl, dragEl.offsetWidth / 2, dragEl.offsetHeight / 2);
                setTimeout(() => document.body.removeChild(dragEl), 0);
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
            const x_mm = Math.max(0, (e.clientX - bandRect.left) * mmPerPxX);
            const y_mm = Math.max(0, (e.clientY - bandRect.top) * mmPerPxY);

            const elWidth = parseFloat(e.dataTransfer.getData('element-width')) || null;
            const elHeight = parseFloat(e.dataTransfer.getData('element-height')) || null;
            const fieldName = e.dataTransfer.getData('field-name') || null;
            this.addElement(type, bandType, this.snapValue(x_mm), this.snapValue(y_mm), fieldName, elWidth, elHeight);
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
                const bandEl = el.closest('.band');
                const bandType = bandEl.dataset.bandType;
                const bandData = this.bands.find(b => b.type === bandType);
                const origTop = parseFloat(el.style.top);
                const origLeft = parseFloat(el.style.left);
                const origHeight = parseFloat(el.style.height);
                const pxToMm = getPxToMm();

                const expandBand = () => {
                    if (!bandData || !bandEl) return;
                    const newTop = parseFloat(el.style.top);
                    const bottom = newTop + origHeight;
                    if (bottom > bandData.height) {
                        bandData.height = this.snapValue(bottom);
                        bandEl.style.height = bandData.height + 'mm';
                    }
                };

                const onMove = (me) => {
                    const dx = (me.clientX - startX) * pxToMm;
                    const dy = (me.clientY - startY) * pxToMm;
                    el.style.left = Math.max(0, this.snapValue(origLeft + dx)) + 'mm';
                    el.style.top = Math.max(0, this.snapValue(origTop + dy)) + 'mm';
                    expandBand();
                };

                const onUp = () => {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    this.pushUndoState();
                    const band = this.findBandForElement(elId);
                    if (band) {
                        const elem = band.elements.find(e => e.id === elId);
                        if (elem) {
                            elem.top = Math.max(0, parseFloat(el.style.top));
                            elem.left = Math.max(0, parseFloat(el.style.left));
                            this.clearFontMetrics();
                            window.ReportingEngine.dispatch('SET_DIRTY', true);
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
                const bandEl = el.closest('.band');
                const bandType = bandEl.dataset.bandType;
                const bandData = this.bands.find(b => b.type === bandType);
                const origTop = parseFloat(el.style.top);
                const origW = parseFloat(el.style.width);
                const origH = parseFloat(el.style.height);
                const startX = e.clientX;
                const startY = e.clientY;
                const pxToMm = getPxToMm();

                const expandBand = () => {
                    if (!bandData || !bandEl) return;
                    const newH = parseFloat(el.style.height);
                    const bottom = origTop + newH;
                    if (bottom > bandData.height) {
                        bandData.height = this.snapValue(bottom);
                        bandEl.style.height = bandData.height + 'mm';
                    }
                };

                const onMove = (me) => {
                    const dw = (me.clientX - startX) * pxToMm;
                    const dh = (me.clientY - startY) * pxToMm;
                    el.style.width = Math.max(1, this.snapValue(origW + dw)) + 'mm';
                    el.style.height = Math.max(1, this.snapValue(origH + dh)) + 'mm';
                    expandBand();
                };

                const onUp = () => {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    this.pushUndoState();
                    const band = this.findBandForElement(elId);
                    if (band) {
                        const elem = band.elements.find(e => e.id === elId);
                        if (elem) {
                            elem.width = parseFloat(el.style.width);
                            elem.height = parseFloat(el.style.height);
                            this.clearFontMetrics();
                            window.ReportingEngine.dispatch('SET_DIRTY', true);
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
                const bandData = this.bands.find(b => b.type === bandType);
                const startY = e.clientY;
                const origH = parseFloat(band.style.height);
                const pxToMm = getPxToMm();

                // Minimum height that doesn't clip any element
                const minH = () => {
                    if (!bandData || !bandData.elements) return 1;
                    let maxBottom = 0;
                    for (const el of bandData.elements) {
                        const bottom = (el.top || 0) + (el.height || 0);
                        if (bottom > maxBottom) maxBottom = bottom;
                    }
                    return Math.max(1, maxBottom);
                };

                const onMove = (me) => {
                    const dh = (me.clientY - startY) * pxToMm;
                    const newH = Math.max(minH(), this.snapValue(origH + dh));
                    band.style.height = newH + 'mm';
                };

                const onUp = () => {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    const b = this.bands.find(b => b.type === bandType);
                    if (b) {
                        b.height = parseFloat(band.style.height);
                        window.ReportingEngine.dispatch('SET_DIRTY', true);
                    }
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
        return Math.max(0, Math.round(val / grid)) * grid;
    }

    getElementDefaults(type) {
        const sizes = {
            label:     { width: 50, height: 10, text: 'Label' },
            field:     { width: 50, height: 10, text: null, fieldText: '[Field]' },
            aggregate: { width: 50, height: 10, text: null, fieldText: '{AGG}' },
            image:     { width: 50, height: 10, text: null },
            line:      { width: 50, height: 10, text: null },
            rect:      { width: 50, height: 10, text: null },
            pageno:    { width: 50, height: 10, text: '{PAGENO}' },
            pagecount: { width: 50, height: 10, text: '{PAGECOUNT}' },
            rowno:     { width: 50, height: 10, text: '{ROWNO}' },
            datetime:  { width: 50, height: 10, text: null },
            barcode:   { width: 50, height: 20, text: null },
        };
        return sizes[type] || { width: 50, height: 10, text: null };
    }

    addElement(type, bandType, x, y, fieldName, elWidth, elHeight) {
        this.pushUndoState();
        const band = this.bands.find(b => b.type === bandType);
        if (!band) return;

        if (!band.elements) band.elements = [];

        const def = window.ReportingEngine.state.definition;
        const ds = def.defaultStyle || {};
        const defaults = this.getElementDefaults(type);
        const w = elWidth || defaults.width;
        const h = elHeight || defaults.height;
        const isTextEl = ['label', 'field', 'pageno', 'pagecount', 'rowno', 'datetime'].includes(type);
        const el = {
            id: 'el-' + Date.now() + '-' + Math.random().toString(36).substr(2, 4),
            type: type,
            top: y,
            left: x,
            width: w,
            height: h,
            text: type === 'label' ? 'Label' : null,
            fieldName: type === 'field' ? (fieldName || '') : null,
            fontFamily: ds.fontFamily || (type === 'field' || type === 'rowno' ? 'Courier New' : 'Arial'),
            fontSize: ds.fontSize || 10,
            bold: false,
            italic: false,
            underline: false,
            color: ds.color || '#000000',
            textAlign: 'left',
            verticalAlign: isTextEl ? 'middle' : 'top',
            backgroundColor: ds.backgroundColor || 'transparent',
            border: {},
            inheritStyle: true,
            wordWrap: false,
            visibleExpression: null,
        };

        if (type === 'aggregate') {
            el.aggregateFunc = 'sum';
            el.aggregateScope = bandType === 'group_footer' ? 'group' : 'report';
            el.format = '#,##0.00';
        }

        if (type === 'barcode') {
            el.barcodeSymbology = 'code128';
            el.barcodeShowText = true;
            el.barcodeExpression = '';
        }

        band.elements.push(el);
        this.renderCanvas();
        this.renderObjectTree();

        // Open aggregate editor for aggregate elements
        if (type === 'aggregate' && window.aggregateEditor) {
            window.aggregateEditor.open(el.id);
        }
        window.ReportingEngine.dispatch('SET_DIRTY', true);
        this.clearFontMetrics();
    }

    removeElement(id) {
        this.pushUndoState();
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
        window.ReportingEngine.dispatch('SET_DIRTY', true);
        this.clearFontMetrics();
    }

    findBandByType(type, groupField) {
        return this.bands.find(b => b.type === type && (!groupField || b.groupField === groupField));
    }

    addSubtotal(fieldElementId) {
        this.pushUndoState();
        const band = this.findBandForElement(fieldElementId);
        if (!band) return;
        const el = band.elements.find(e => e.id === fieldElementId);
        if (!el || !el.fieldName) {
            this.showToast('Selected element has no field name', 'error');
            return;
        }

        // Find matching group
        const group = (window.ReportingEngine.state.definition.groups || [])
            .find(g => g.fieldName === el.fieldName);
        if (!group) {
            this.showToast(`Field "${el.fieldName}" is not grouped. Add a group first.`, 'error');
            return;
        }

        // Find or create group_footer band
        let footerBand = this.findBandByType('group_footer', group.fieldName);
        if (!footerBand) {
            // Insert after detail band (or last group_header if this is a new group)
            const insertIdx = this.bands.findIndex(b => b.type === 'detail') + 1;
            footerBand = {
                type: 'group_footer',
                groupField: group.fieldName,
                groupLevel: group.level ?? 0,
                height: 18,
                backgroundColor: 'transparent',
                border: {},
                elements: [],
            };
            this.bands.splice(insertIdx, 0, footerBand);

            // Also ensure group_header exists
            const headerBand = this.findBandByType('group_header', group.fieldName);
            if (!headerBand) {
                this.bands.splice(insertIdx, 0, {
                    type: 'group_header',
                    groupField: group.fieldName,
                    groupLevel: group.level ?? 0,
                    height: 18,
                    backgroundColor: 'transparent',
                    border: {},
                    elements: [],
                });
            }
        }

        // Check for existing aggregate for this field + scope
        const existing = footerBand.elements.find(e =>
            e.type === 'aggregate' && e.fieldName === el.fieldName && e.aggregateScope === 'group'
        );
        if (existing) {
            this.showToast(`Subtotal for "${el.fieldName}" already exists`, 'error');
            return;
        }

        // Create aggregate element
        const aggEl = {
            id: 'el-' + Date.now() + '-' + Math.random().toString(36).substr(2, 4),
            type: 'aggregate',
            top: 0,
            left: el.left,
            width: el.width,
            height: el.height,
            text: null,
            fieldName: el.fieldName,
            fontFamily: el.fontFamily || 'Arial',
            fontSize: el.fontSize || 10,
            bold: el.bold || false,
            italic: el.italic || false,
            underline: el.underline || false,
            color: el.color || '#000000',
            textAlign: el.textAlign || 'left',
            verticalAlign: el.verticalAlign || 'middle',
            backgroundColor: el.backgroundColor || 'transparent',
            border: JSON.parse(JSON.stringify(el.border || {})),
            inheritStyle: false,
            wordWrap: false,
            visibleExpression: null,
            aggregateFunc: 'sum',
            aggregateScope: 'group',
            format: '#,##0.00',
        };

        footerBand.elements.push(aggEl);
        this.renderCanvas();
        this.renderObjectTree();
        this.selectElement(aggEl.id);
        window.ReportingEngine.dispatch('SET_DIRTY', true);
        this.clearFontMetrics();
        this.showToast(`Subtotal added for "${el.fieldName}"`);
    }

    addGrandTotal(fieldElementId) {
        this.pushUndoState();
        const band = this.findBandForElement(fieldElementId);
        if (!band) return;
        const el = band.elements.find(e => e.id === fieldElementId);
        if (!el || !el.fieldName) {
            this.showToast('Selected element has no field name', 'error');
            return;
        }

        // Find or create report_footer band
        let footerBand = this.findBandByType('report_footer');
        if (!footerBand) {
            const pfIdx = this.bands.findIndex(b => b.type === 'page_footer');
            const insertIdx = pfIdx >= 0 ? pfIdx : this.bands.length;
            this.bands.splice(insertIdx, 0, {
                type: 'report_footer',
                height: 22,
                backgroundColor: 'transparent',
                border: {},
                elements: [],
            });
            footerBand = this.findBandByType('report_footer');
        }

        // Check for existing aggregate for this field + scope
        const existing = footerBand.elements.find(e =>
            e.type === 'aggregate' && e.fieldName === el.fieldName && e.aggregateScope === 'report'
        );
        if (existing) {
            this.showToast(`Grand total for "${el.fieldName}" already exists`, 'error');
            return;
        }

        const aggEl = {
            id: 'el-' + Date.now() + '-' + Math.random().toString(36).substr(2, 4),
            type: 'aggregate',
            top: 0,
            left: el.left,
            width: el.width,
            height: el.height,
            text: null,
            fieldName: el.fieldName,
            fontFamily: el.fontFamily || 'Arial',
            fontSize: el.fontSize || 10,
            bold: el.bold || false,
            italic: el.italic || false,
            underline: el.underline || false,
            color: el.color || '#000000',
            textAlign: el.textAlign || 'left',
            verticalAlign: el.verticalAlign || 'middle',
            backgroundColor: el.backgroundColor || 'transparent',
            border: JSON.parse(JSON.stringify(el.border || {})),
            inheritStyle: false,
            wordWrap: false,
            visibleExpression: null,
            aggregateFunc: 'sum',
            aggregateScope: 'report',
            format: '#,##0.00',
        };

        footerBand.elements.push(aggEl);
        this.renderCanvas();
        this.renderObjectTree();
        this.selectElement(aggEl.id);
        window.ReportingEngine.dispatch('SET_DIRTY', true);
        this.clearFontMetrics();
        this.showToast(`Grand total added for "${el.fieldName}"`);
    }

    copyElement(id) {
        const band = this.findBandForElement(id);
        if (!band) return;
        const elem = band.elements.find(e => e.id === id);
        if (!elem) return;
        window.clipboard.element = JSON.parse(JSON.stringify(elem));
        window.clipboard.isCut = false;
    }

    cutElement(id) {
        const band = this.findBandForElement(id);
        if (!band) return;
        const elem = band.elements.find(e => e.id === id);
        if (!elem) return;
        window.clipboard.element = JSON.parse(JSON.stringify(elem));
        window.clipboard.isCut = true;
        this.removeElement(id);
    }

    pasteElement(targetBandType, cursorX, cursorY) {
        this.pushUndoState();
        if (!window.clipboard || !window.clipboard.element) return;

        const band = this.bands.find(b => b.type === targetBandType);
        if (!band) return;
        if (!band.elements) band.elements = [];

        const src = window.clipboard.element;
        const el = JSON.parse(JSON.stringify(src));
        el.id = 'el-' + Date.now() + '-' + Math.random().toString(36).substr(2, 4);

        // Convert cursor position to mm coordinates within the target band
        const bandEl = this.canvasInner.querySelector(`.band[data-band-type="${targetBandType}"]`);
        if (bandEl && cursorX != null && cursorY != null) {
            const bandRect = bandEl.getBoundingClientRect();
            const canvasRect = this.canvasInner.getBoundingClientRect();
            const mmPerPxY = band.height / bandRect.height;
            const mmPerPxX = this.canvasUsableWidth / canvasRect.width;
            el.left = this.snapValue(Math.max(0, (cursorX - bandRect.left) * mmPerPxX));
            el.top = this.snapValue(Math.max(0, (cursorY - bandRect.top) * mmPerPxY));
        } else {
            el.left = this.snapValue(10);
            el.top = this.snapValue(10);
        }

        band.elements.push(el);
        this.renderCanvas();
        this.renderObjectTree();
        this.selectElement(el.id);
        window.ReportingEngine.dispatch('SET_DIRTY', true);
        this.clearFontMetrics();
    }

    getSelectedElements() {
        const ids = this.selectedElementIds.length > 0 ? this.selectedElementIds
            : (window.ReportingEngine.state.selectedElement ? [window.ReportingEngine.state.selectedElement] : []);
        const result = [];
        for (const id of ids) {
            const band = this.findBandForElement(id);
            if (!band) continue;
            const el = band.elements.find(e => e.id === id);
            if (el) result.push({ el, band });
        }
        return result;
    }

    updateAlignmentButtons() {
        const count = this.getSelectedElements().length;
        document.querySelectorAll('.align-btn, .distribute-btn').forEach(btn => {
            btn.disabled = count < 2;
            btn.classList.toggle('disabled', count < 2);
        });
    }

    alignElements(direction) {
        this.pushUndoState();
        const selected = this.getSelectedElements();
        if (selected.length < 2) {
            this.showToast('Select at least 2 elements in the same band', 'error');
            return;
        }
        const bandType = selected[0].band.type;
        if (selected.some(s => s.band.type !== bandType)) {
            this.showToast('Elements must be in the same band', 'error');
            return;
        }

        let ref;
        if (direction === 'left') ref = Math.min(...selected.map(s => s.el.left));
        else if (direction === 'right') ref = Math.max(...selected.map(s => s.el.left + s.el.width));
        else if (direction === 'top') ref = Math.min(...selected.map(s => s.el.top));
        else if (direction === 'bottom') ref = Math.max(...selected.map(s => s.el.top + s.el.height));
        else if (direction === 'middle') {
            const avg = selected.reduce((sum, s) => sum + s.el.top + s.el.height / 2, 0) / selected.length;
            for (const { el } of selected) {
                el.top = this.snapValue(Math.max(0, avg - el.height / 2));
            }
            this.renderCanvas();
            window.ReportingEngine.dispatch('SET_DIRTY', true);
            return;
        } else if (direction === 'center') {
            const avg = selected.reduce((sum, s) => sum + s.el.left + s.el.width / 2, 0) / selected.length;
            for (const { el } of selected) {
                el.left = this.snapValue(Math.max(0, avg - el.width / 2));
            }
            this.renderCanvas();
            window.ReportingEngine.dispatch('SET_DIRTY', true);
            return;
        }

        if (direction === 'left' || direction === 'right') {
            for (const { el } of selected) {
                el.left = direction === 'left'
                    ? this.snapValue(ref)
                    : this.snapValue(ref - el.width);
                el.left = Math.max(0, el.left);
            }
        } else if (direction === 'top' || direction === 'bottom') {
            for (const { el } of selected) {
                el.top = direction === 'top'
                    ? this.snapValue(ref)
                    : this.snapValue(ref - el.height);
                el.top = Math.max(0, el.top);
            }
        }

        this.renderCanvas();
        window.ReportingEngine.dispatch('SET_DIRTY', true);
    }

    distributeElements(direction) {
        this.pushUndoState();
        const selected = this.getSelectedElements();
        if (selected.length < 2) {
            this.showToast('Select at least 2 elements in the same band', 'error');
            return;
        }
        const bandType = selected[0].band.type;
        if (selected.some(s => s.band.type !== bandType)) {
            this.showToast('Elements must be in the same band', 'error');
            return;
        }

        if (direction === 'horizontal') {
            selected.sort((a, b) => a.el.left - b.el.left);
            const totalWidth = selected.reduce((sum, s) => sum + s.el.width, 0);
            const firstLeft = selected[0].el.left;
            const lastRight = selected[selected.length - 1].el.left + selected[selected.length - 1].el.width;
            const gap = (lastRight - firstLeft - totalWidth) / (selected.length - 1);
            let pos = firstLeft;
            for (const { el } of selected) {
                el.left = this.snapValue(Math.max(0, pos));
                pos += el.width + gap;
            }
        } else if (direction === 'vertical') {
            selected.sort((a, b) => a.el.top - b.el.top);
            const totalHeight = selected.reduce((sum, s) => sum + s.el.height, 0);
            const firstTop = selected[0].el.top;
            const lastBottom = selected[selected.length - 1].el.top + selected[selected.length - 1].el.height;
            const gap = (lastBottom - firstTop - totalHeight) / (selected.length - 1);
            let pos = firstTop;
            for (const { el } of selected) {
                el.top = this.snapValue(Math.max(0, pos));
                pos += el.height + gap;
            }
        }

        this.renderCanvas();
        window.ReportingEngine.dispatch('SET_DIRTY', true);
    }

    duplicateElement(id) {
        this.pushUndoState();
        const band = this.findBandForElement(id);
        if (!band) return;
        const src = band.elements.find(e => e.id === id);
        if (!src) return;

        const el = JSON.parse(JSON.stringify(src));
        el.id = 'el-' + Date.now() + '-' + Math.random().toString(36).substr(2, 4);
        el.left = this.snapValue((src.left || 0) + (src.width || 10) + 5);
        el.top = this.snapValue(src.top || 0);

        band.elements.push(el);
        this.renderCanvas();
        this.renderObjectTree();
        this.selectElement(el.id);
        window.ReportingEngine.dispatch('SET_DIRTY', true);
        this.clearFontMetrics();
    }

    copyStyle(id) {
        const band = this.findBandForElement(id);
        if (!band) return;
        const elem = band.elements.find(e => e.id === id);
        if (!elem) return;
        window.clipboard.style = {
            fontFamily: elem.fontFamily,
            fontSize: elem.fontSize,
            bold: elem.bold,
            italic: elem.italic,
            underline: elem.underline,
            color: elem.color,
            textAlign: elem.textAlign,
            verticalAlign: elem.verticalAlign,
            backgroundColor: elem.backgroundColor,
            border: JSON.parse(JSON.stringify(elem.border || {})),
            width: elem.width,
            height: elem.height,
        };
    }

    pasteStyle(id) {
        this.pushUndoState();
        if (!window.clipboard || !window.clipboard.style) return;
        const band = this.findBandForElement(id);
        if (!band) return;
        const elem = band.elements.find(e => e.id === id);
        if (!elem) return;

        const s = window.clipboard.style;
        elem.fontFamily = s.fontFamily;
        elem.fontSize = s.fontSize;
        elem.bold = s.bold;
        elem.italic = s.italic;
        elem.underline = s.underline;
        elem.color = s.color;
        elem.textAlign = s.textAlign;
        elem.verticalAlign = s.verticalAlign;
        elem.backgroundColor = s.backgroundColor;
        elem.border = JSON.parse(JSON.stringify(s.border));
        elem.width = s.width;
        elem.height = s.height;

        this.renderCanvas();
        if (window.ReportingEngine.state.selectedElement === id) {
            this.selectElement(id);
        }
        window.ReportingEngine.dispatch('SET_DIRTY', true);
    }

    applyDefaultStyleToElements(field, oldVal, newVal) {
        const fieldMap = {
            fontFamily: 'fontFamily',
            fontSize: 'fontSize',
            color: 'color',
            backgroundColor: 'backgroundColor',
        };
        const elField = fieldMap[field];
        if (elField === undefined) return;
        if (oldVal === newVal) return;
        for (const band of this.bands) {
            if (!band.elements) continue;
            for (const el of band.elements) {
                if (el.inheritStyle !== false && el[elField] === oldVal) {
                    el[elField] = newVal;
                }
            }
        }
    }

    moveElement(id, dx, dy) {
        this.pushUndoState();
        const band = this.findBandForElement(id);
        if (!band) return;
        const elem = band.elements.find(e => e.id === id);
        if (!elem) return;
        elem.top = Math.max(0, this.snapValue(elem.top + dy));
        elem.left = Math.max(0, this.snapValue(elem.left + dx));
        const bottom = (elem.top || 0) + (elem.height || 0);
        if (bottom > band.height) {
            band.height = this.snapValue(bottom);
        }
        this.renderCanvas();
        if (window.ReportingEngine.state.selectedElement === id) {
            this.selectElement(id);
        }
        window.ReportingEngine.dispatch('SET_DIRTY', true);
    }

    setZoom(zoom) {
        this.zoom = zoom;
        window.ReportingEngine.dispatch('SET_ZOOM', zoom);
        this.renderCanvas();
    }

    resetHistory() {
        window.ReportingEngine.state.history = [];
        window.ReportingEngine.state.historyIndex = -1;
    }

    pushUndoState() {
        const snapshot = {
            def: JSON.parse(JSON.stringify(window.ReportingEngine.state.definition)),
            bands: JSON.parse(JSON.stringify(this.bands)),
            selectedElement: window.ReportingEngine.state.selectedElement || null,
        };
        const history = window.ReportingEngine.state.history;
        // Prune future if we branched
        if (window.ReportingEngine.state.historyIndex < history.length - 1) {
            history.splice(window.ReportingEngine.state.historyIndex + 1);
        }
        history.push(snapshot);
        // Limit stack size
        if (history.length > 100) {
            history.shift();
        }
        window.ReportingEngine.state.historyIndex = history.length - 1;
    }

    undo() {
        const history = window.ReportingEngine.state.history;
        if (window.ReportingEngine.state.historyIndex <= 0) return;
        window.ReportingEngine.state.historyIndex--;
        this.restoreSnapshot(history[window.ReportingEngine.state.historyIndex]);
    }

    redo() {
        const history = window.ReportingEngine.state.history;
        if (window.ReportingEngine.state.historyIndex >= history.length - 1) return;
        window.ReportingEngine.state.historyIndex++;
        this.restoreSnapshot(history[window.ReportingEngine.state.historyIndex]);
    }

    restoreSnapshot(snapshot) {
        window.ReportingEngine.dispatch('LOAD_DEFINITION', snapshot.def);
        this.bands = JSON.parse(JSON.stringify(snapshot.bands));
        if (snapshot.selectedElement) {
            this.selectElement(snapshot.selectedElement);
        } else {
            window.ReportingEngine.dispatch('SELECT_ELEMENT', null);
            document.querySelectorAll('.canvas-element, .band').forEach(el => el.classList.remove('selected'));
        }
        this.renderCanvas();
        this.renderObjectTree();
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
                const oldKey = this.storageKey();
                res = await window.ReportingEngine.api('POST', '/api/reports', payload);
                if (res.data && res.data.id) {
                    this.reportId = res.data.id;
                    this.reportGuid = res.data.guid || null;
                    window.ReportingEngine.state.activeReportId = this.reportId;
                    window.ReportingEngine.state.activeReportGuid = this.reportGuid;
                    history.replaceState(null, '', `/reports/designer/${this.reportId}`);
                    localStorage.removeItem(oldKey);
                    localStorage.removeItem(oldKey + '_dirty');
                }
            }
            if (res.data && res.data.guid) {
                def.guid = res.data.guid;
                if (!this.reportGuid) {
                    this.reportGuid = res.data.guid;
                    window.ReportingEngine.state.activeReportGuid = this.reportGuid;
                }
            }
            window.ReportingEngine.dispatch('SET_DIRTY', false);
            this.autosave();
            if (res.success) {
                this.showToast('Report saved successfully');
            } else {
                this.showToast(res.message || 'Failed to save report', 'error');
            }
        } catch (e) {
            this.showToast('Failed to save report', 'error');
        }
    }

    async saveAsTemplate() {
        const name = prompt('Template name:', this.getReportName() + ' (Template)');
        if (!name) return;
        const description = prompt('Description (optional):', '');
        const def = window.ReportingEngine.state.definition;
        def.bands = this.bands;
        try {
            const res = await window.ReportingEngine.api('POST', '/api/report-templates', {
                name,
                description: description || '',
                definition: JSON.stringify(def),
            });
            if (res.success) {
                this.showToast('Template saved successfully');
            } else {
                this.showToast('Failed to save template: ' + (res.message || 'Unknown error'), 'error');
            }
        } catch (e) {
            this.showToast('Failed to save template', 'error');
        }
    }

    getReportName() {
        const def = window.ReportingEngine.state.definition;
        return def.name || 'Untitled Report';
    }

    preview() {
        const id = this.reportGuid || this.reportId || '';
        if (!id) { this.showToast('Save the report first', 'error'); return; }
        this.autosave();
        window.location.href = '/reports/preview/' + id + '?unsaved=1';
    }

    postRenderRequest(format) {
        const def = window.ReportingEngine.state.definition;
        def.bands = this.bands;
        const fontMetrics = this.measureFontMetrics(def);
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/api/render/preview';
        form.target = '_blank';
        const inputs = [
            { name: 'json', value: JSON.stringify(def) },
            { name: 'format', value: format },
        ];
        if (Object.keys(fontMetrics).length > 0) {
            inputs.push({ name: '_fontMetrics', value: JSON.stringify(fontMetrics) });
        }
        inputs.forEach(({ name, value }) => {
            const el = document.createElement('input');
            el.type = 'hidden'; el.name = name; el.value = value;
            form.appendChild(el);
        });
        document.body.appendChild(form);
        form.submit();
        form.remove();
    }

    measureFontMetrics(definition) {
        if (definition.fontMetrics && typeof definition.fontMetrics === 'object' && Object.keys(definition.fontMetrics).length > 0) {
            return definition.fontMetrics;
        }
        const allBands = [];
        if (definition.bands) {
            for (const key in definition.bands) allBands.push(definition.bands[key]);
        }
        const combos = new Map();
        for (const band of allBands) {
            if (!band || !band.elements) continue;
            for (const el of band.elements) {
                if (el.wordWrap && !['image', 'line', 'rect'].includes(el.type)) {
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
        if (combos.size === 0) {
            definition.fontMetrics = {};
            return {};
        }
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
        definition.fontMetrics = results;
        return results;
    }

    clearFontMetrics() {
        const def = window.ReportingEngine.state.definition;
        if (def && def.fontMetrics) {
            delete def.fontMetrics;
        }
    }

    exportPdf() {
        if (!this.reportId) { this.showToast('Save the report first', 'error'); return; }
        this.postRenderRequest('pdf');
    }

    exportHtml() {
        if (!this.reportId) { this.showToast('Save the report first', 'error'); return; }
        this.postRenderRequest('html');
    }

    async exportDesign() {
        const id = this.reportGuid || this.reportId;
        if (!id) { this.showToast('Save the report first', 'error'); return; }
        try {
            const res = await window.ReportingEngine.api('GET', `/api/reports/${id}/export`);
            if (res.success && res.data) {
                const blob = new Blob([JSON.stringify(res.data, null, 2)], { type: 'application/json' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = (res.data.name || 'report').replace(/[^a-zA-Z0-9_-]/g, '_') + '_report.json';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                this.showToast('Report design exported');
            } else {
                this.showToast('Export failed: ' + (res.message || 'Unknown error'), 'error');
            }
        } catch (e) {
            this.showToast('Failed to export design', 'error');
        }
    }

    importDesign() {
        document.getElementById('import-file-input').click();
    }

    async handleImportFile(event) {
        const file = event.target.files[0];
        if (!file) return;
        event.target.value = '';
        try {
            const text = await file.text();
            const data = JSON.parse(text);
            if (data.type !== 'report-export') {
                this.showToast('Invalid export file', 'error');
                return;
            }
            const res = await window.ReportingEngine.api('POST', '/api/reports/import', data);
            if (res.success && res.data) {
                window.location.href = `/reports/designer/${res.data.id}`;
            } else {
                this.showToast('Import failed: ' + (res.message || 'Unknown error'), 'error');
            }
        } catch (e) {
            this.showToast('Failed to import design: ' + e.message, 'error');
        }
    }

    undo() {
        const stack = window.ReportingEngine.state.undoStack;
        if (stack.length === 0) return;
        const current = JSON.stringify(window.ReportingEngine.state.definition);
        window.ReportingEngine.state.redoStack.push(current);
        const prev = JSON.parse(stack.pop());
        this.bands = prev.bands || this.getDefaultBands();
        window.ReportingEngine.dispatch('UNDO_STACK', stack);
        window.ReportingEngine.dispatch('SET_DEFINITION', prev);
        this.renderCanvas();
        this.renderObjectTree();
    }

    redo() {
        const stack = window.ReportingEngine.state.redoStack;
        if (stack.length === 0) return;
        const current = JSON.stringify(window.ReportingEngine.state.definition);
        window.ReportingEngine.state.undoStack.push(current);
        const next = JSON.parse(stack.pop());
        this.bands = next.bands || this.getDefaultBands();
        window.ReportingEngine.dispatch('REDO_STACK', stack);
        window.ReportingEngine.dispatch('SET_DEFINITION', next);
        this.renderCanvas();
        this.renderObjectTree();
    }

    renderObjectTree() {
        const container = document.getElementById('object-tree');
        if (!container) return;
        const reportSelected = !window.ReportingEngine.state.selectedBand && !window.ReportingEngine.state.selectedElement;
        const bandOrder = ['page_header', 'report_header', 'group_header', 'column_header', 'detail', 'group_footer', 'report_footer', 'page_footer'];
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
                                <i class="ph-${el.type === 'label' ? 'text-t' : el.type === 'field' ? 'database' : el.type === 'aggregate' ? 'function' : el.type === 'rowno' ? 'list-numbers' : 'square'}"></i>
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
