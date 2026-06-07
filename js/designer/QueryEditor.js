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
    }

    async init() {
        await this.loadConnections();
        if (this.designer.reportId) {
            await this.loadReportQuery();
        }
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

    async loadTableColumns(tableName) {
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

    renderTableList() {
        if (!this.tables || this.tables.length === 0) {
            this.tableListEl.innerHTML = '<p class="text-muted" style="font-size:12px;padding:2px 0">No tables found</p>';
            return;
        }
        this.tableListEl.innerHTML = this.tables.map(t => {
            const name = t.name || t.table_name || t;
            return `<div class="table-item" onclick="queryEditor.loadTableColumns('${name.replace(/'/g, "\\'")}')">
                <i class="ph-table"></i>
                <span>${name}</span>
            </div>`;
        }).join('');
    }

    onSqlChange() {
        const sql = this.sqlTextarea.value;
        window.ReportingEngine.state.definition.query = window.ReportingEngine.state.definition.query || {};
        window.ReportingEngine.state.definition.query.sql = sql;
        window.ReportingEngine.state.definition.sqlQuery = sql;
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

    setStatus(message, type) {
        this.statusEl.textContent = message;
        this.statusEl.style.color = type === 'error' ? 'var(--color-danger)'
            : type === 'success' ? 'var(--color-success)'
            : 'var(--color-text-muted)';
    }
}
