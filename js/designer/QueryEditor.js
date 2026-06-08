class QueryEditor {
    constructor(designer) {
        this.designer = designer;
        this.connectionSelect = document.getElementById('query-connection');
        this.sqlTextarea = document.getElementById('query-sql');
        this.statusEl = document.getElementById('query-status');
        this.fieldList = document.getElementById('field-list');
        this.tableListEl = document.getElementById('table-list');
        this.connections = [];
        this.queryColumns = [];
        this.tables = [];
        this.lastResultRows = [];
        this.lastResultColumns = [];
        this.savedQuerySql = '';
        this.savedConnectionId = null;
    }

    async init() {
        await this.loadConnections();
        if (this.designer.reportId) {
            await this.loadReportQuery();
        }
        this.renderParameters();
    }

    async loadConnections() {
        try {
            const res = await window.ReportingEngine.api('GET', '/api/connections');
            this.connections = res.data || [];
            this.connectionSelect.innerHTML =
                '<option value="">Select connection...</option>' +
                this.connections.map(c =>
                    `<option value="${c.id}">${c.name} (${c.driver})</option>`
                ).join('');
        } catch (e) {
            this.setStatus('Failed to load connections', 'error');
        }
    }

    async loadReportQuery() {
        try {
            const res = await window.ReportingEngine.api('GET', `/api/reports/${this.designer.reportId}`);
            if (!res.data) return;
            const def = typeof res.data.definition === 'string'
                ? JSON.parse(res.data.definition) : res.data.definition;
            this.savedQuerySql = def.query?.sql || '';
            this.savedConnectionId = def.connectionId || null;
            if (def.query?.sql) {
                this.sqlTextarea.value = def.query.sql;
            }
            if (def.connectionId) {
                this.connectionSelect.value = def.connectionId;
                this.loadTables(parseInt(def.connectionId));
            }
            // Load saved query columns if any
            if (def.queryColumns && def.queryColumns.length > 0) {
                this.queryColumns = def.queryColumns;
                this.renderFieldList();
            }
        } catch (e) {
            // Silent fail for new reports
        }
    }

    onConnectionChange() {
        const connId = this.connectionSelect.value;
        window.ReportingEngine.state.definition.connectionId = connId ? parseInt(connId) : null;
        if (connId) {
            this.loadTables(parseInt(connId));
        } else {
            this.tableListEl.innerHTML = '<p class="text-muted" style="font-size:12px;padding:2px 0">Select a connection</p>';
            this.tables = [];
        }
    }

    async loadTables(connId) {
        this.tableListEl.innerHTML = '<p class="text-muted" style="font-size:12px;padding:2px 0">Loading tables...</p>';
        try {
            const res = await window.ReportingEngine.api('GET', `/api/connections/${connId}/tables`);
            this.tables = res.data || [];
            this.renderTableList();
        } catch (e) {
            this.tableListEl.innerHTML = '<p class="text-muted" style="font-size:12px;padding:2px 0;color:var(--color-danger)">Failed to load tables</p>';
        }
    }

    renderTableList() {
        if (!this.tables || this.tables.length === 0) {
            this.tableListEl.innerHTML = '<p class="text-muted" style="font-size:12px;padding:2px 0">No tables found</p>';
            return;
        }
        if (!this._tableColumnCache) this._tableColumnCache = {};
        this.tableListEl.innerHTML = this.tables.map(t => {
            const name = t.name || t.table_name || t;
            const safeName = name.replace(/'/g, "\\'");
            return `<div class="table-item" data-table-name="${safeName}">
                <i class="ph-caret-right table-caret" onclick="queryEditor.toggleTableColumns('${safeName}', this)"></i>
                <i class="ph-table"></i>
                <span class="table-name-link" onclick="queryEditor.toggleTableColumns('${safeName}')">${name}</span>
                <i class="ph-play table-select-sql" onclick="queryEditor.selectTable('${safeName}')" title="Generate SELECT query"></i>
            </div>
            <div class="table-columns" id="tcols-${safeName}" style="display:none"></div>`;
        }).join('');
    }

    async selectTable(tableName) {
        const connId = this.connectionSelect.value;
        if (!connId) return;
        try {
            const res = await window.ReportingEngine.api('GET', `/api/connections/${connId}/tables/${encodeURIComponent(tableName)}/columns`);
            const columns = res.data || [];
            const colNames = columns.map(c => c.name || c.column_name).filter(Boolean);
            if (colNames.length > 0) {
                this.sqlTextarea.value = `SELECT ${colNames.join(', ')}\nFROM ${tableName}`;
            } else {
                this.sqlTextarea.value = `SELECT *\nFROM ${tableName}`;
            }
            this.onSqlChange();
        } catch (e) {
            this.setStatus('Failed to load columns', 'error');
        }
    }

    async toggleTableColumns(tableName, caretEl) {
        const colsDiv = document.getElementById('tcols-' + tableName);
        if (!colsDiv) return;

        // Find caret if not provided
        if (!caretEl) {
            const tableItem = this.tableListEl.querySelector(`.table-item[data-table-name="${tableName}"]`);
            if (tableItem) caretEl = tableItem.querySelector('.table-caret');
        }

        if (colsDiv.style.display === 'block') {
            colsDiv.style.display = 'none';
            if (caretEl) caretEl.classList.remove('open');
            return;
        }

        if (this._tableColumnCache[tableName]) {
            colsDiv.style.display = 'block';
            if (caretEl) caretEl.classList.add('open');
            colsDiv.innerHTML = this._tableColumnCache[tableName];
            return;
        }

        if (caretEl) caretEl.classList.add('open');
        colsDiv.style.display = 'block';
        colsDiv.innerHTML = '<div class="table-col-loading">Loading...</div>';

        const connId = this.connectionSelect.value;
        if (!connId) { colsDiv.innerHTML = ''; return; }

        try {
            const res = await window.ReportingEngine.api('GET', `/api/connections/${connId}/tables/${encodeURIComponent(tableName)}/columns`);
            const columns = res.data || [];
            let html = '';
            for (const col of columns) {
                const colName = col.name || col.column_name || '';
                const colType = col.type || '';
                html += `<div class="table-col-item" draggable="true" data-field-name="${colName}">
                    <i class="ph-${colType.toLowerCase().includes('int') || colType.toLowerCase().includes('float') ? 'hash' : 'text-aa'}"></i>
                    <span class="table-col-name">${colName}</span>
                    <span class="table-col-type">${colType}</span>
                </div>`;
            }
            if (!html) html = '<div class="text-muted" style="font-size:11px;padding:4px 8px">No columns</div>';
            this._tableColumnCache[tableName] = html;
            colsDiv.innerHTML = html;

            // Enable drag from column items
            colsDiv.querySelectorAll('.table-col-item').forEach(item => {
                item.addEventListener('dragstart', (e) => {
                    e.dataTransfer.setData('text/plain', 'field');
                    e.dataTransfer.setData('field-name', item.dataset.fieldName);
                });
            });
        } catch (e) {
            colsDiv.innerHTML = '<div class="text-muted" style="font-size:11px;padding:4px 8px;color:var(--color-danger)">Failed to load</div>';
        }
    }

    onSqlChange() {
        const sql = this.sqlTextarea.value;
        window.ReportingEngine.state.definition.query = window.ReportingEngine.state.definition.query || {};
        window.ReportingEngine.state.definition.query.sql = sql;
        window.ReportingEngine.state.definition.sqlQuery = sql;
        this.detectParameters(sql);
    }

    detectParameters(sql) {
        const def = window.ReportingEngine.state.definition;
        def.query = def.query || {};
        const existing = def.query.parameters || [];
        const namedParams = [...sql.matchAll(/:\b([a-zA-Z_]\w*)\b/g)].map(m => m[1]);
        const existingNames = new Set(existing.map(p => typeof p === 'string' ? p : p.name));
        let changed = false;
        for (const name of namedParams) {
            if (!existingNames.has(name)) {
                existing.push({ name, type: 'string', defaultValue: '' });
                existingNames.add(name);
                changed = true;
            }
        }
        if (changed) {
            def.query.parameters = existing;
            this.renderParameters();
        }
    }

    getParameters() {
        const def = window.ReportingEngine.state.definition;
        def.query = def.query || {};
        if (!def.query.parameters) def.query.parameters = [];
        return def.query.parameters;
    }

    renderParameters() {
        const container = document.getElementById('parameter-list');
        if (!container) return;
        const params = this.getParameters();
        if (params.length === 0) {
            container.innerHTML = '<p class="text-muted" style="font-size:11px;padding:2px 0">No parameters defined. Use <code>:name</code> in SQL to auto-detect.</p>';
            return;
        }
        container.innerHTML = params.map((p, i) => {
            const name = typeof p === 'string' ? p : (p.name || '');
            const type = typeof p === 'string' ? 'string' : (p.type || 'string');
            const defaultValue = typeof p === 'string' ? '' : (p.defaultValue || '');
            return `<div class="param-row" style="display:flex;gap:4px;align-items:center;margin-bottom:4px">
                <input class="prop-control param-name" style="flex:2;min-width:0;font-family:var(--font-mono);font-size:11px" type="text" value="${escapeHtml(name)}" placeholder="name" onchange="queryEditor.updateParameter(${i},'name',this.value)">
                <select class="prop-control param-type" style="flex:1;min-width:0;font-size:11px" onchange="queryEditor.updateParameter(${i},'type',this.value)">
                    <option value="string" ${type === 'string' ? 'selected' : ''}>text</option>
                    <option value="number" ${type === 'number' ? 'selected' : ''}>number</option>
                    <option value="date" ${type === 'date' ? 'selected' : ''}>date</option>
                    <option value="boolean" ${type === 'boolean' ? 'selected' : ''}>bool</option>
                </select>
                <input class="prop-control param-default" style="flex:1;min-width:0;font-size:11px" type="text" value="${escapeHtml(defaultValue)}" placeholder="default" onchange="queryEditor.updateParameter(${i},'defaultValue',this.value)">
                <button class="btn btn-icon btn-sm" style="flex-shrink:0;color:var(--color-danger)" onclick="queryEditor.removeParameter(${i})" title="Remove"><i class="ph-x"></i></button>
            </div>`;
        }).join('');
    }

    addParameter() {
        const params = this.getParameters();
        params.push({ name: '', type: 'string', defaultValue: '' });
        window.ReportingEngine.dispatch('SET_DIRTY', true);
        this.renderParameters();
    }

    removeParameter(index) {
        const params = this.getParameters();
        params.splice(index, 1);
        window.ReportingEngine.dispatch('SET_DIRTY', true);
        this.renderParameters();
    }

    resetQuery() {
        this.sqlTextarea.value = this.savedQuerySql;
        this.onSqlChange();
        if (this.savedConnectionId) {
            this.connectionSelect.value = this.savedConnectionId;
            this.loadTables(parseInt(this.savedConnectionId));
        } else {
            this.connectionSelect.value = '';
            this.tableListEl.innerHTML = '<p class="text-muted" style="font-size:12px;padding:2px 0">Select a connection</p>';
        }
        this.setStatus('Query reset to saved version', '');
    }

    updateParameter(index, field, value) {
        const params = this.getParameters();
        let p = params[index];
        if (typeof p === 'string') {
            p = { name: p, type: 'string', defaultValue: '' };
            params[index] = p;
        }
        p[field] = value;
        window.ReportingEngine.dispatch('SET_DIRTY', true);
    }

    async runQuery() {
        const connId = this.connectionSelect.value;
        const sql = this.sqlTextarea.value.trim();

        if (!sql) {
            this.setStatus('Please enter a SQL query', 'error');
            return;
        }

        this.setStatus('Running query...', '');
        try {
            const res = await window.ReportingEngine.api('POST', '/api/query/execute', {
                connection_id: connId ? parseInt(connId) : 0,
                sql: sql,
                limit: 50,
            });

            if (!res.success) {
                this.setStatus(res.message || 'Query failed', 'error');
                return;
            }

            this.queryColumns = res.data.columns || [];
            window.ReportingEngine.dispatch('SET_QUERY_COLUMNS', this.queryColumns);
            this.renderFieldList();

            this.lastResultColumns = this.queryColumns;
            this.lastResultRows = res.data.rows || [];
            this.renderResultTable();

            const rowCount = res.data.rowCount || 0;
            this.setStatus(`Query OK — ${rowCount} rows, ${this.queryColumns.length} columns`, 'success');

            // Update report definition
            this.onSqlChange();
            window.ReportingEngine.state.definition.queryColumns = this.queryColumns;

        } catch (e) {
            this.setStatus('Query error: ' + e.message, 'error');
        }
    }

    async applyFields() {
        const connId = this.connectionSelect.value;
        const sql = this.sqlTextarea.value.trim();

        if (!sql) {
            this.setStatus('Please enter a SQL query first', 'error');
            return;
        }

        this.setStatus('Extracting fields...', '');
        try {
            const res = await window.ReportingEngine.api('POST', '/api/query/fields', {
                connection_id: connId ? parseInt(connId) : 0,
                sql: sql,
            });

            if (!res.success) {
                this.setStatus(res.message || 'Failed to extract fields', 'error');
                return;
            }

            this.queryColumns = res.data || [];
            window.ReportingEngine.dispatch('SET_QUERY_COLUMNS', this.queryColumns);
            this.renderFieldList();

            this.onSqlChange();
            window.ReportingEngine.state.definition.queryColumns = this.queryColumns;

            this.setStatus(`Applied ${this.queryColumns.length} fields`, 'success');
        } catch (e) {
            this.setStatus('Field extraction error: ' + e.message, 'error');
        }
    }

    renderFieldList() {
        if (!this.queryColumns || this.queryColumns.length === 0) {
            this.fieldList.innerHTML = '<p class="text-muted" style="font-size:12px;padding:4px 0">No fields available</p>';
            return;
        }

        this.fieldList.innerHTML = this.queryColumns.map(col => {
            const icon = this.getFieldIcon(col.type);
            return `<div class="field-item" draggable="true" data-field-name="${col.name}">
                <i class="${icon}"></i>
                <span>${col.name}</span>
                <small style="color:#94a3b8;margin-left:auto;font-size:10px">${col.type || ''}</small>
            </div>`;
        }).join('');

        // Enable drag from field list
        document.querySelectorAll('.field-item').forEach(item => {
            item.addEventListener('dragstart', (e) => {
                e.dataTransfer.setData('text/plain', 'field');
                e.dataTransfer.setData('field-name', item.dataset.fieldName);
            });
        });
    }

    getFieldIcon(type) {
        const t = (type || '').toLowerCase();
        if (t.includes('int') || t.includes('float') || t.includes('double') || t.includes('decimal') || t.includes('numeric')) {
            return 'ph-hash';
        }
        if (t.includes('date') || t.includes('time') || t.includes('timestamp')) {
            return 'ph-calendar';
        }
        return 'ph-text-aa';
    }

    renderResultTable() {
        const container = document.getElementById('query-result-table');
        const body = document.getElementById('query-result-body');
        if (!container || !body) return;

        const cols = this.lastResultColumns;
        const rows = this.lastResultRows.slice(0, 10);

        if (rows.length === 0) {
            container.style.display = 'none';
            return;
        }

        let html = '<div class="query-result-scroll"><table><thead><tr>';
        for (const col of cols) {
            html += `<th>${escapeHtml(col.name || '')}</th>`;
        }
        html += '</tr></thead><tbody>';
        for (const row of rows) {
            html += '<tr>';
            for (let j = 0; j < cols.length; j++) {
                const val = row[j] !== null && row[j] !== undefined ? String(row[j]) : '';
                html += `<td>${escapeHtml(val)}</td>`;
            }
            html += '</tr>';
        }
        html += '</tbody></table></div>';
        if (this.lastResultRows.length > 10) {
            html += `<div style="font-size:11px;color:var(--color-text-muted);padding:4px 0">Showing 10 of ${this.lastResultRows.length} rows</div>`;
        }
        body.innerHTML = html;
        container.style.display = 'block';
    }

    closeResultTable() {
        const container = document.getElementById('query-result-table');
        if (container) container.style.display = 'none';
    }

    setStatus(message, type) {
        this.statusEl.textContent = message;
        this.statusEl.style.color = type === 'error' ? 'var(--color-danger)'
            : type === 'success' ? 'var(--color-success)'
            : 'var(--color-text-muted)';
    }
}
