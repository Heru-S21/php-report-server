class BandManager {
    constructor(designer) {
        this.designer = designer;
    }

    addGroupBands(groupField, groupLevel) {
        this.designer.pushUndoState();
        const bands = this.designer.bands;
        const headerExists = bands.some(b => b.type === 'group_header' && b.groupField === groupField);
        const footerExists = bands.some(b => b.type === 'group_footer' && b.groupField === groupField);
        if (headerExists || footerExists) return;

        // Remove existing auto group bands at this level
        const insertBeforeType = groupLevel === 0 ? 'detail' : 'group_footer';

        const headerBand = {
            type: 'group_header',
            groupField: groupField,
            groupLevel: groupLevel,
            height: 18,
            printOnEveryPage: false,
            visible: true,
            keepTogether: false,
            backgroundColor: 'transparent',
            border: {},
            elements: [],
        };

        const footerBand = {
            type: 'group_footer',
            groupField: groupField,
            groupLevel: groupLevel,
            height: 18,
            printOnEveryPage: false,
            visible: true,
            keepTogether: false,
            backgroundColor: 'transparent',
            border: {},
            elements: [],
        };

        bands.push(headerBand);
        bands.push(footerBand);
        this.designer.renderCanvas();
    }

    removeGroupBands(groupField) {
        this.designer.pushUndoState();
        this.designer.bands = this.designer.bands.filter(
            b => !((b.type === 'group_header' || b.type === 'group_footer') && b.groupField === groupField)
        );
        this.designer.renderCanvas();
    }

    reorderGroupBands() {
        const groups = window.ReportingEngine.state.definition.groups || [];
        if (!groups.length) return;

        const sorted = [...groups].sort((a, b) => a.level - b.level);
        const byField = {};
        sorted.forEach((g, i) => { byField[g.fieldName] = i; });

        // Separate bands by type, preserving non-group order
        const groupHeaders = this.designer.bands.filter(b => b.type === 'group_header');
        const groupFooters = this.designer.bands.filter(b => b.type === 'group_footer');
        const otherBands = this.designer.bands.filter(b => b.type !== 'group_header' && b.type !== 'group_footer');

        // Sort group bands to match group order
        groupHeaders.sort((a, b) => (byField[a.groupField] ?? 0) - (byField[b.groupField] ?? 0));
        groupFooters.sort((a, b) => (byField[a.groupField] ?? 0) - (byField[b.groupField] ?? 0));

        // Replace bands, keeping original positions: group_headers stay before other bands,
        // group_footers after. Since renderCanvas sorts by bandOrder, we just need headers
        // before footers in the array and everything will render correctly.
        this.designer.bands = [...groupHeaders, ...otherBands, ...groupFooters];
        this.designer.renderCanvas();
    }

    toggleBandVisibility(bandType, visible) {
        this.designer.pushUndoState();
        const band = this.designer.bands.find(b => b.type === bandType);
        if (band) {
            band.visible = visible;
            this.designer.renderCanvas();
            window.ReportingEngine.dispatch('SET_DIRTY', true);
        }
    }

    resizeBand(bandType, height) {
        this.designer.pushUndoState();
        const band = this.designer.bands.find(b => b.type === bandType);
        if (!band) return;
        let minH = 1;
        if (band.elements) {
            for (const el of band.elements) {
                const bottom = (el.top || 0) + (el.height || 0);
                if (bottom > minH) minH = bottom;
            }
        }
        band.height = Math.max(minH, height);
        this.designer.renderCanvas();
        window.ReportingEngine.dispatch('SET_DIRTY', true);
    }
}
