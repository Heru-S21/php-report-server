class AggregateEditor {
    constructor(designer) {
        this.designer = designer;
        this.modal = document.getElementById('aggregate-modal');
        this.elementId = null;
    }

    open(elementId) {
        this.elementId = elementId;
        const band = this.findBand(elementId);
        if (!band) { this.close(); return; }
        const el = band.elements.find(e => e.id === elementId);
        if (!el) { this.close(); return; }

        const fields = window.ReportingEngine.state.queryColumns || [];
        const select = document.getElementById('agg-field-select');
        select.innerHTML = fields.map(f =>
            `<option value="${f.name}" ${el.fieldName === f.name ? 'selected' : ''}>${f.name}</option>`
        ).join('');

        document.getElementById('agg-func-select').value = el.aggregateFunc || 'sum';
        document.getElementById('agg-scope-select').value = el.aggregateScope || 'group';
        document.getElementById('agg-format').value = el.format || '#,##0.00';

        this.modal.style.display = 'flex';
    }

    close() {
        this.modal.style.display = 'none';
        this.elementId = null;
    }

    save() {
        if (!this.elementId) return;
        const band = this.findBand(this.elementId);
        if (!band) return;
        const el = band.elements.find(e => e.id === this.elementId);
        if (!el) return;

        el.fieldName = document.getElementById('agg-field-select').value;
        el.aggregateFunc = document.getElementById('agg-func-select').value;
        el.aggregateScope = document.getElementById('agg-scope-select').value;
        el.format = document.getElementById('agg-format').value;

        this.designer.renderCanvas();
        window.ReportingEngine.dispatch('SET_DIRTY', true);
        this.close();
    }

    findBand(elementId) {
        for (const b of this.designer.bands) {
            if (b.elements && b.elements.some(e => e.id === elementId)) return b;
        }
        return null;
    }
}
