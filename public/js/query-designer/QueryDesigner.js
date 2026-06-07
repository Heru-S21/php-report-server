class QueryDesigner {
    constructor(containerId, connectionId) {
        this.container = document.getElementById(containerId);
        this.connectionId = connectionId;
        this.tables = [];
        this.joins = [];
        this.where = [];
        this.orderBy = [];
        this.groupBy = [];
        this.limit = null;
        this.tableCards = {};
        this.init();
    }

    async init() {
        if (!this.connectionId) return;
        try {
            const res = await window.ReportingEngine.api('GET', `/api/connections/${this.connectionId}/tables`);
            this.availableTables = res.data || [];
            this.renderTableBrowser();
        } catch (e) {
            console.error('Failed to load tables:', e);
        }
    }

    renderTableBrowser() {
        const browser = this.container.querySelector('.query-table-browser');
        if (!browser) return;
        browser.innerHTML = `
            <input type="text" placeholder="Search tables..." oninput="queryDesigner.filterTables(this.value)">
            <div class="table-list">
                ${(this.availableTables || []).map(t => `
                    <div class="query-table-item" onclick="queryDesigner.addTableToCanvas('${t}')">
                        <span><i class="ph-table"></i> ${t}</span>
                        <i class="ph-plus-circle" style="color:var(--color-primary)"></i>
                    </div>
                `).join('')}
            </div>
        `;
    }

    filterTables(query) {
        const items = this.container.querySelectorAll('.query-table-item');
        items.forEach(item => {
            const name = item.querySelector('span').textContent.trim();
            item.style.display = name.toLowerCase().includes(query.toLowerCase()) ? 'flex' : 'none';
        });
    }

    async addTableToCanvas(tableName) {
        const canvas = this.container.querySelector('.query-join-canvas');
        if (!canvas) return;

        const alias = tableName.substring(0, 1).toLowerCase();
        const usedAliases = Object.values(this.tableCards).map(t => t.alias);
        let finalAlias = alias;
        let counter = 1;
        while (usedAliases.includes(finalAlias)) {
            finalAlias = alias + counter;
            counter++;
        }

        try {
            const res = await window.ReportingEngine.api('GET',
                `/api/connections/${this.connectionId}/tables/${tableName}/columns`);
            const columns = res.data || [];

            const card = document.createElement('div');
            card.className = 'query-table-card';
            card.dataset.tableName = tableName;
            card.style.left = (50 + Object.keys(this.tableCards).length * 40) + 'px';
            card.style.top = (50 + Object.keys(this.tableCards).length * 30) + 'px';

            card.innerHTML = `
                <div class="card-header">
                    <span>${tableName} <small style="font-weight:400">(${finalAlias})</small></span>
                    <button class="btn btn-icon" onclick="this.closest('.query-table-card').remove(); queryDesigner.removeTable('${tableName}')">
                        <i class="ph-x"></i>
                    </button>
                </div>
                <div class="card-body">
                    ${columns.map(col => `
                        <div class="card-column" draggable="true" data-column="${col.name}" data-table="${tableName}">
                            <input type="checkbox" checked onchange="queryDesigner.toggleColumn('${tableName}','${col.name}', this.checked)">
                            <span>${col.name}</span>
                            <small style="color:#94a3b8;margin-left:auto">${col.type || ''}</small>
                        </div>
                    `).join('')}
                </div>
            `;

            // Make card draggable
            this.makeCardDraggable(card);

            canvas.appendChild(card);

            this.tableCards[tableName] = {
                name: tableName,
                alias: finalAlias,
                columns: columns,
                selectedColumns: columns.map(c => c.name),
            };

            this.updateSql();

        } catch (e) {
            console.error('Failed to load columns:', e);
        }
    }

    removeTable(tableName) {
        delete this.tableCards[tableName];
        this.joins = this.joins.filter(j => j.leftTable !== tableName && j.rightTable !== tableName);
        this.updateSql();
    }

    makeCardDraggable(card) {
        let isDragging = false;
        let startX, startY, origLeft, origTop;

        card.addEventListener('mousedown', (e) => {
            if (e.target.closest('.btn') || e.target.closest('input')) return;
            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;
            origLeft = parseInt(card.style.left);
            origTop = parseInt(card.style.top);
        });

        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            card.style.left = (origLeft + e.clientX - startX) + 'px';
            card.style.top = (origTop + e.clientY - startY) + 'px';
        });

        document.addEventListener('mouseup', () => {
            isDragging = false;
        });
    }

    toggleColumn(tableName, columnName, selected) {
        if (this.tableCards[tableName]) {
            const cols = this.tableCards[tableName].selectedColumns;
            if (selected && !cols.includes(columnName)) cols.push(columnName);
            else if (!selected) this.tableCards[tableName].selectedColumns = cols.filter(c => c !== columnName);
            this.updateSql();
        }
    }

    updateSql() {
        const sql = this.generateSql();
        const display = this.container.querySelector('.query-sql-display pre');
        if (display) display.textContent = sql;
        // Sync to SQL editor
        const sqlEditor = document.getElementById('sql-editor');
        if (sqlEditor) sqlEditor.value = sql;
    }

    generateSql() {
        const tables = Object.values(this.tableCards);
        if (tables.length === 0) return '-- Add tables to build a query';

        const selectCols = [];
        for (const t of tables) {
            for (const col of t.selectedColumns) {
                selectCols.push(`${t.alias}.${col}`);
            }
        }

        const select = selectCols.length > 0 ? selectCols.join(', ') : '*';
        const from = tables.map(t => `${t.name} ${t.alias}`).join(', ');
        const joins = this.joins.map(j =>
            `${j.type || 'INNER'} JOIN ${j.rightTable} ${this.getAlias(j.rightTable)} ON ${j.leftTable}.${j.leftCol} = ${this.getAlias(j.rightTable)}.${j.rightCol}`
        ).join('\n');

        let sql = `SELECT ${select}\nFROM ${from}`;
        if (joins) sql += `\n${joins}`;

        if (this.where.length > 0) {
            sql += '\nWHERE ' + this.where.map(w =>
                `${w.field} ${w.operator} ${w.value}`
            ).join(` ${this.where[0]?.conjunction || 'AND'} `);
        }

        if (this.orderBy.length > 0) {
            sql += '\nORDER BY ' + this.orderBy.map(o => `${o.field} ${o.direction}`).join(', ');
        }

        if (this.groupBy.length > 0) {
            sql += '\nGROUP BY ' + this.groupBy.map(g => g.field).join(', ');
        }

        if (this.limit) sql += `\nLIMIT ${this.limit}`;

        return sql;
    }

    getAlias(tableName) {
        return this.tableCards[tableName]?.alias || tableName.substring(0, 1).toLowerCase();
    }
}
