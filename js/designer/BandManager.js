class BandManager {
    constructor(designer) {
        this.designer = designer;
    }

    addGroupBands(groupField, groupLevel) {
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
        this.designer.bands = this.designer.bands.filter(
            b => !((b.type === 'group_header' || b.type === 'group_footer') && b.groupField === groupField)
        );
        this.designer.renderCanvas();
    }

    toggleBandVisibility(bandType, visible) {
        const band = this.designer.bands.find(b => b.type === bandType);
        if (band) {
            band.visible = visible;
            this.designer.renderCanvas();
        }
    }

    resizeBand(bandType, height) {
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
    }
}
