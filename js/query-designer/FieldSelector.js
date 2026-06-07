class FieldSelector {
    constructor(connectionId) {
        this.connectionId = connectionId;
    }

    async loadFields() {
        try {
            // Try to get fields from the current SQL query
            const sql = document.getElementById('sql-editor')?.value;
            if (!sql) return [];
            const res = await window.ReportingEngine.api('POST', '/api/query/fields', {
                connection_id: this.connectionId,
                sql: sql,
            });
            window.ReportingEngine.dispatch('SET_QUERY_COLUMNS', res.data || []);
            return res.data || [];
        } catch (e) {
            console.error('Failed to load fields:', e);
            return [];
        }
    }
}
