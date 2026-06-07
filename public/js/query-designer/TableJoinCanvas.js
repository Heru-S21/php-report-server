class TableJoinCanvas {
    constructor(container) {
        this.container = container;
        this.svg = null;
        this.lines = [];
    }

    init() {
        this.svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        this.svg.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:10;';
        this.container.appendChild(this.svg);
    }

    drawLine(x1, y1, x2, y2, color = '#2563EB', width = 2) {
        const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line.setAttribute('x1', x1);
        line.setAttribute('y1', y1);
        line.setAttribute('x2', x2);
        line.setAttribute('y2', y2);
        line.setAttribute('stroke', color);
        line.setAttribute('stroke-width', width);
        line.setAttribute('stroke-dasharray', '5,3');
        line.style.pointerEvents = 'auto';
        line.style.cursor = 'pointer';
        this.svg.appendChild(line);
        this.lines.push(line);
        return line;
    }

    removeAll() {
        while (this.svg.firstChild) {
            this.svg.removeChild(this.svg.firstChild);
        }
        this.lines = [];
    }

    refresh(tableCards) {
        this.removeAll();
        const keys = Object.keys(tableCards);
        for (let i = 0; i < keys.length; i++) {
            for (let j = i + 1; j < keys.length; j++) {
                const card1 = this.container.querySelector(`[data-table-name="${keys[i]}"]`);
                const card2 = this.container.querySelector(`[data-table-name="${keys[j]}"]`);
                if (card1 && card2) {
                    const r1 = card1.getBoundingClientRect();
                    const r2 = card2.getBoundingClientRect();
                    const containerRect = this.container.getBoundingClientRect();
                    this.drawLine(
                        r1.right - containerRect.left,
                        r1.top + r1.height / 2 - containerRect.top,
                        r2.left - containerRect.left,
                        r2.top + r2.height / 2 - containerRect.top
                    );
                }
            }
        }
    }
}
