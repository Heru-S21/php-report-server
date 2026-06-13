class BorderEditor {
    constructor(designer) {
        this.designer = designer;
        this.target = null;
    }

    render(container, target) {
        this.target = target;
        if (!target.border || Array.isArray(target.border)) target.border = {};
        const b = target.border;
        ['top', 'right', 'bottom', 'left'].forEach(side => {
            if (!b[side]) b[side] = { enabled: false, width: 1, style: 'solid', color: '#000000' };
        });

        const curSide = b.top.enabled ? b.top : b.right.enabled ? b.right : b.bottom.enabled ? b.bottom : b.left.enabled ? b.left : b.top;
        const curStyle = curSide.style;
        const curWidth = curSide.width;
        const curColor = curSide.color;

        container.innerHTML = `
            <div class="border-editor">
                <div class="border-sides">
                    <label><input type="checkbox" ${b.top.enabled ? 'checked' : ''}
                        onchange="window.borderEditor.toggleSide('top', this.checked)"> Top</label>
                    <label><input type="checkbox" ${b.right.enabled ? 'checked' : ''}
                        onchange="window.borderEditor.toggleSide('right', this.checked)"> Right</label>
                    <label><input type="checkbox" ${b.bottom.enabled ? 'checked' : ''}
                        onchange="window.borderEditor.toggleSide('bottom', this.checked)"> Bottom</label>
                    <label><input type="checkbox" ${b.left.enabled ? 'checked' : ''}
                        onchange="window.borderEditor.toggleSide('left', this.checked)"> Left</label>
                    <label style="border-top:1px solid #e2e8f0;padding-top:4px;margin-top:4px">
                        <input type="checkbox" onchange="window.borderEditor.toggleAll(this.checked)"> All
                    </label>
                </div>
                <div class="border-controls">
                    <div id="border-preview" class="border-preview-box">Preview</div>
                    <div class="prop-group">
                        <label>Width (px)</label>
                        <input class="prop-control" type="number" value="${curWidth}" min="0" max="10"
                               id="border-width" onchange="window.borderEditor.updateStyle('width', parseInt(this.value) || 1)">
                    </div>
                    <div class="prop-group">
                        <label>Style</label>
                        <select class="prop-control" id="border-style"
                                onchange="window.borderEditor.updateStyle('style', this.value)">
                            <option value="solid" ${curStyle === 'solid' ? 'selected' : ''}>Solid</option>
                            <option value="dashed" ${curStyle === 'dashed' ? 'selected' : ''}>Dashed</option>
                            <option value="dotted" ${curStyle === 'dotted' ? 'selected' : ''}>Dotted</option>
                            <option value="double" ${curStyle === 'double' ? 'selected' : ''}>Double</option>
                            <option value="none" ${curStyle === 'none' ? 'selected' : ''}>None</option>
                        </select>
                    </div>
                    <div class="prop-group">
                        <label>Color</label>
                        <input class="prop-control" type="color" value="${curColor}"
                               id="border-color" onchange="window.borderEditor.updateStyle('color', this.value)">
                    </div>
                </div>
            </div>
        `;

        this.updatePreview();
    }

    toggleSide(side, enabled) {
        if (!this.target || !this.target.border) return;
        this.target.border[side].enabled = enabled;
        this.updatePreview();
        this.designer.renderCanvas();
        window.ReportingEngine.dispatch('SET_DIRTY', true);
    }

    toggleAll(enabled) {
        if (!this.target || !this.target.border) return;
        ['top', 'right', 'bottom', 'left'].forEach(side => {
            this.target.border[side].enabled = enabled;
        });
        this.updatePreview();
        this.designer.renderCanvas();
        window.ReportingEngine.dispatch('SET_DIRTY', true);
    }

    updateStyle(field, value) {
        if (!this.target || !this.target.border) return;
        ['top', 'right', 'bottom', 'left'].forEach(side => {
            if (this.target.border[side].enabled) {
                this.target.border[side][field] = value;
            }
        });
        if (field === 'style' && value === 'double') {
            ['top', 'right', 'bottom', 'left'].forEach(side => {
                if (this.target.border[side].enabled && this.target.border[side].width < 3) {
                    this.target.border[side].width = 3;
                }
            });
        }
        this.updatePreview();
        this.designer.renderCanvas();
        window.ReportingEngine.dispatch('SET_DIRTY', true);
    }

    updatePreview() {
        const preview = document.getElementById('border-preview');
        if (!preview || !this.target || !this.target.border) return;
        const b = this.target.border;
        let css = '';
        ['top', 'right', 'bottom', 'left'].forEach(side => {
            const s = b[side];
            if (s && s.enabled) {
                const w = s.style === 'double' ? Math.max(3, s.width) : s.width;
                css += `border-${side}: ${w}px ${s.style} ${s.color};`;
            }
        });
        preview.style.cssText = `width:120px; height:80px; display:flex; align-items:center; justify-content:center; font-size:11px; color:#999; ${css}`;
    }
}
