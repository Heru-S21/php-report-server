class ContextMenu {
    constructor(designer) {
        this.designer = designer;
        this.menuEl = null;
        this.targetElementId = null;
        this.targetBandType = null;
        this.cursorX = 0;
        this.cursorY = 0;
        this.init();
    }

    init() {
        this.menuEl = document.createElement('div');
        this.menuEl.className = 'context-menu';
        this.menuEl.style.display = 'none';
        document.body.appendChild(this.menuEl);

        document.addEventListener('click', () => this.hide());
        document.addEventListener('contextmenu', () => this.hide(), true);

        this.designer.canvasInner.addEventListener('contextmenu', (e) => {
            const el = e.target.closest('.canvas-element');
            const band = e.target.closest('.band');

            this.cursorX = e.clientX;
            this.cursorY = e.clientY;

            if (el) {
                e.preventDefault();
                this.designer.selectElement(el.dataset.elementId);
                this.targetElementId = el.dataset.elementId;
                const bandEl = el.closest('.band');
                this.targetBandType = bandEl ? bandEl.dataset.bandType : null;
                this.show(e.clientX, e.clientY, true);
                return;
            }

            if (band) {
                e.preventDefault();
                this.designer.selectBand(band.dataset.bandType);
                this.targetElementId = null;
                this.targetBandType = band.dataset.bandType;
                this.show(e.clientX, e.clientY, false);
                return;
            }
        });
    }

    show(x, y, onElement) {
        const items = [];

        if (onElement) {
            items.push(
                { icon: 'ph-copy', label: 'Copy', shortcut: '⌘C', action: () => this.designer.copyElement(this.targetElementId) },
                { icon: 'ph-scissors', label: 'Cut', shortcut: '⌘X', action: () => this.designer.cutElement(this.targetElementId) },
            );
        }

        // Subtotal / Grand Total for field elements
        if (onElement) {
            const band = this.designer.findBandForElement(this.targetElementId);
            const elem = band ? band.elements.find(e => e.id === this.targetElementId) : null;
            if (elem && elem.type === 'field') {
                items.push(
                    { separator: true },
                    { icon: 'ph-calculator', label: 'Add Subtotal', action: () => this.designer.addSubtotal(this.targetElementId) },
                    { icon: 'ph-calculator', label: 'Add Grand Total', action: () => this.designer.addGrandTotal(this.targetElementId) },
                );
            }
        }

        items.push({
            icon: 'ph-clipboard-text',
            label: 'Paste',
            shortcut: '⌘V',
            action: () => this.designer.pasteElement(this.targetBandType, this.cursorX, this.cursorY),
            enabled: !!window.clipboard && !!window.clipboard.element,
        });

        if (onElement) {
            items.push(
                { icon: 'ph-copy', label: 'Duplicate', shortcut: '⌘D', action: () => this.designer.duplicateElement(this.targetElementId) },
                { separator: true },
                { icon: 'ph-trash', label: 'Delete', shortcut: '⌦', action: () => this.designer.removeElement(this.targetElementId) },
                { separator: true },
                { icon: 'ph-paint-brush', label: 'Copy Style', action: () => this.designer.copyStyle(this.targetElementId) },
                {
                    icon: 'ph-paint-bucket',
                    label: 'Paste Style',
                    action: () => this.designer.pasteStyle(this.targetElementId),
                    enabled: !!window.clipboard && !!window.clipboard.style,
                },
            );
        }

        this.menuEl.innerHTML = '';
        let hasVisible = false;
        for (const item of items) {
            if (item.separator) {
                const sep = document.createElement('div');
                sep.className = 'context-menu-separator';
                this.menuEl.appendChild(sep);
                continue;
            }
            const enabled = item.enabled !== false;
            const div = document.createElement('div');
            div.className = 'context-menu-item' + (enabled ? '' : ' disabled');
            div.innerHTML = `<i class="${item.icon}"></i><span>${item.label}</span>${item.shortcut ? `<small>${item.shortcut}</small>` : ''}`;
            if (enabled) {
                div.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.hide();
                    item.action();
                });
            }
            this.menuEl.appendChild(div);
            hasVisible = true;
        }

        if (!hasVisible) { this.hide(); return; }

        this.menuEl.style.display = 'block';

        // Position within viewport
        const rect = this.menuEl.getBoundingClientRect();
        let mx = x, my = y;
        if (mx + rect.width > window.innerWidth) mx = window.innerWidth - rect.width - 4;
        if (my + rect.height > window.innerHeight) my = window.innerHeight - rect.height - 4;
        this.menuEl.style.left = Math.max(0, mx) + 'px';
        this.menuEl.style.top = Math.max(0, my) + 'px';
    }

    hide() {
        if (this.menuEl) this.menuEl.style.display = 'none';
    }
}
