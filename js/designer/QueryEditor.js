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
            // Use in-memory definition first (may include unsaved draft edits)
            const currentDef = window.ReportingEngine.state.definition;
            let sql = currentDef.query?.sql || currentDef.sqlQuery || '';
            if (sql) {
                this.sqlTextarea.value = sql;
            }
            if (currentDef.connectionId) {
                this.connectionSelect.value = currentDef.connectionId;
                this.loadTables(parseInt(currentDef.connectionId));
            }
            if (currentDef.queryColumns && currentDef.queryColumns.length > 0) {
                this.queryColumns = currentDef.queryColumns;
                this.renderFieldList();
            }

            // Also fetch server version for saved SQL (used by Reset button)
            // and as fallback if state.definition wasn't populated yet
            const res = await window.ReportingEngine.api('GET', `/api/reports/${this.designer.reportId}`);
            if (res.data) {
                const def = typeof res.data.definition === 'string'
                    ? JSON.parse(res.data.definition) : res.data.definition;
                this.savedQuerySql = def.query?.sql || '';
                this.savedConnectionId = def.connectionId || null;

                // Fallback: if state didn't have SQL/connection/columns, apply from server
                if (!sql && def.query?.sql) {
                    this.sqlTextarea.value = def.query.sql;
                    sql = def.query.sql;
                }
                if (!currentDef.connectionId && def.connectionId) {
                    this.connectionSelect.value = def.connectionId;
                    this.loadTables(parseInt(def.connectionId));
                }
                if ((!currentDef.queryColumns || currentDef.queryColumns.length === 0) && def.queryColumns?.length > 0) {
                    this.queryColumns = def.queryColumns;
                    this.renderFieldList();
                }
            }
        } catch (e) {
            // Silent fail for new reports
        }
    }

    onConnectionChange() {
        const connId = this.connectionSelect.value;
        window.ReportingEngine.state.definition.connectionId = connId ? parseInt(connId) : null;
        window.ReportingEngine.dispatch('SET_DIRTY', true);
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
            const res = await window.ReportingEngine.api('GET', `/api/connections/${connId}/table-columns?table=${encodeURIComponent(tableName)}`);
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
            const res = await window.ReportingEngine.api('GET', `/api/connections/${connId}/table-columns?table=${encodeURIComponent(tableName)}`);
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
                    e.dataTransfer.setData('element-width', '50');
                    e.dataTransfer.setData('element-height', '10');
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
        window.ReportingEngine.dispatch('SET_DIRTY', true);
    }

    detectParameters(sql) {
        const def = window.ReportingEngine.state.definition;
        def.query = def.query || {};
        const namedParams = [...sql.matchAll(/:\b([a-zA-Z_]\w*)\b/g)].map(m => m[1]);
        const uniqueNames = [...new Set(namedParams)];
        const existing = def.query.parameters || [];

        // Keep only params still present in SQL, preserving custom config
        const kept = existing.filter(p => {
            const name = typeof p === 'string' ? p : p.name;
            return uniqueNames.includes(name);
        });

        const keptNames = new Set(kept.map(p => typeof p === 'string' ? p : p.name));
        let changed = kept.length !== existing.length;
        for (const name of uniqueNames) {
            if (!keptNames.has(name)) {
                kept.push({ name, type: 'string', defaultValue: '' });
                changed = true;
            }
        }

        if (changed) {
            def.query.parameters = kept;
            this.renderParameters();
            this.renderFieldList();
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
        const paramNames = params.map(p => typeof p === 'string' ? p : p.name);
        container.innerHTML = params.map((p, i) => {
            const name = typeof p === 'string' ? p : (p.name || '');
            const type = typeof p === 'string' ? 'string' : (p.type || 'string');
            const defaultValue = typeof p === 'string' ? '' : (p.defaultValue || '');
            const isDropdown = type === 'dropdown' || type === 'multi-select';
            const options = typeof p === 'string' ? '' : (p.options || []);
            const optionsStr = Array.isArray(options) ? options.join('\n') : (options || '');
            const dependsOn = typeof p === 'string' ? '' : (p.dependsOn || '');
            const otherParams = paramNames.filter(n => n !== name);
            const dependsOptions = otherParams.map(n =>
                `<option value="${escapeHtml(n)}"${dependsOn === n ? ' selected' : ''}>${escapeHtml(n)}</option>`
            ).join('');
            return `<div class="param-row" style="display:flex;gap:4px;align-items:center;margin-bottom:4px">
                <input class="prop-control param-name" style="flex:2;min-width:0;font-family:var(--font-mono);font-size:11px" type="text" value="${escapeHtml(name)}" placeholder="name" onchange="queryEditor.updateParameter(${i},'name',this.value)">
                <select class="prop-control param-type" style="flex:1;min-width:0;font-size:11px" onchange="queryEditor.updateParameter(${i},'type',this.value);queryEditor.renderParameters()">
                    <option value="string" ${type === 'string' ? 'selected' : ''}>text</option>
                    <option value="number" ${type === 'number' ? 'selected' : ''}>number</option>
                    <option value="date" ${type === 'date' ? 'selected' : ''}>date</option>
                    <option value="boolean" ${type === 'boolean' ? 'selected' : ''}>bool</option>
                    <option value="dropdown" ${type === 'dropdown' ? 'selected' : ''}>dropdown</option>
                    <option value="multi-select" ${type === 'multi-select' ? 'selected' : ''}>multi-select</option>
                </select>
                <input class="prop-control param-default" style="flex:1;min-width:0;font-size:11px" type="text" value="${escapeHtml(defaultValue)}" placeholder="default" onchange="queryEditor.updateParameter(${i},'defaultValue',this.value)">
                <button class="btn btn-icon btn-sm" style="flex-shrink:0;color:var(--color-danger)" onclick="queryEditor.removeParameter(${i})" title="Remove"><i class="ph-x"></i></button>
            </div>
            ${isDropdown ? `
            <div style="display:flex;gap:4px;margin:0 0 4px 0;padding-left:4px">
                <div style="flex:2">
                    <label style="font-size:10px;text-transform:none;letter-spacing:0;color:var(--color-text-muted)">Options (one per line)</label>
                    <textarea class="prop-control" rows="2" style="font-size:11px;font-family:var(--font-mono);resize:vertical"
                        onchange="queryEditor.updateParameter(${i},'options',this.value.split('\\n').filter(Boolean))"
                        placeholder="Option A&#10;Option B">${escapeHtml(optionsStr)}</textarea>
                </div>
                <div style="flex:1">
                    <label style="font-size:10px;text-transform:none;letter-spacing:0;color:var(--color-text-muted)">Depends on</label>
                    <select class="prop-control" style="font-size:11px" onchange="queryEditor.updateParameter(${i},'dependsOn',this.value)">
                        <option value="">None</option>
                        ${dependsOptions}
                    </select>
                </div>
            </div>` : ''}`;
        }).join('');
    }

    addParameter() {
        const params = this.getParameters();
        params.push({ name: '', type: 'string', defaultValue: '' });
        window.ReportingEngine.dispatch('SET_DIRTY', true);
        this.renderParameters();
        this.renderFieldList();
    }

    removeParameter(index) {
        const params = this.getParameters();
        params.splice(index, 1);
        window.ReportingEngine.dispatch('SET_DIRTY', true);
        this.renderParameters();
        this.renderFieldList();
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

    collectParamValues() {
        const values = {};
        const params = this.getParameters();
        for (const p of params) {
            const name = typeof p === 'string' ? p : p.name;
            if (name) values[name] = p.defaultValue || '';
        }
        return values;
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
                params: this.collectParamValues(),
                limit: 50,
            });

            if (!res.success) {
                this.setStatus(res.message || 'Query failed', 'error');
                return;
            }

            this.lastResultColumns = res.data.columns || [];
            this.lastResultRows = res.data.rows || [];
            this.renderResultTable();

            const rowCount = res.data.rowCount || 0;
            this.setStatus(`Query OK — ${rowCount} rows, ${this.lastResultColumns.length} columns`, 'success');

            this.onSqlChange();

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

        this.setStatus('Running query...', '');
        try {
            const res = await window.ReportingEngine.api('POST', '/api/query/execute', {
                connection_id: connId ? parseInt(connId) : 0,
                sql: sql,
                params: this.collectParamValues(),
                limit: 50,
            });

            if (!res.success) {
                this.setStatus(res.message || 'Query failed', 'error');
                return;
            }

            this.lastResultColumns = res.data.columns || [];
            this.lastResultRows = res.data.rows || [];
            this.renderResultTable();

            const rowCount = res.data.rowCount || 0;
            this.setStatus(`Query OK — ${rowCount} rows, ${this.lastResultColumns.length} columns`, 'success');

            // Extract fields from result columns
            this.queryColumns = this.lastResultColumns.map(col => ({
                name: col.name || col,
                type: col.type || 'text',
            }));
            window.ReportingEngine.dispatch('SET_QUERY_COLUMNS', this.queryColumns);
            window.ReportingEngine.state.definition.queryColumns = this.queryColumns;
            this.renderFieldList();

            this.onSqlChange();

        } catch (e) {
            this.setStatus('Query error: ' + e.message, 'error');
        }
    }

    renderFieldList() {
        const params = this.getParameters();
        let html = '';

        // Parameters section at top
        if (params.length) {
            html += '<div class="field-section-header" style="font-size:10px;font-weight:600;color:#6366f1;padding:4px 8px;text-transform:uppercase;letter-spacing:0.5px">Parameters</div>';
            params.forEach(p => {
                const name = typeof p === 'string' ? p : p.name;
                if (!name) return;
                html += `<div class="field-item" draggable="true" data-field-name=":${name}">
                    <i class="ph-gear" style="color:#6366f1"></i>
                    <span style="color:#6366f1">:${name}</span>
                    <small style="color:#94a3b8;margin-left:auto;font-size:10px">parameter</small>
                </div>`;
            });
        }

        // Regular fields
        if (this.queryColumns && this.queryColumns.length) {
            if (params.length) {
                html += '<div class="field-section-header" style="font-size:10px;font-weight:600;color:#64748b;padding:4px 8px;text-transform:uppercase;letter-spacing:0.5px">Fields</div>';
            }
            html += this.queryColumns.map(col => {
                const icon = this.getFieldIcon(col.type);
                return `<div class="field-item" draggable="true" data-field-name="${col.name}">
                    <i class="${icon}"></i>
                    <span>${col.name}</span>
                    <small style="color:#94a3b8;margin-left:auto;font-size:10px">${col.type || ''}</small>
                </div>`;
            }).join('');
        }

        if (!html) {
            html = '<p class="text-muted" style="font-size:12px;padding:4px 0">No fields available</p>';
        }

        this.fieldList.innerHTML = html;

        // Enable drag from field list
        document.querySelectorAll('.field-item').forEach(item => {
            item.addEventListener('dragstart', (e) => {
                e.dataTransfer.setData('text/plain', 'field');
                e.dataTransfer.setData('field-name', item.dataset.fieldName);
                e.dataTransfer.setData('element-width', '50');
                e.dataTransfer.setData('element-height', '10');
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
