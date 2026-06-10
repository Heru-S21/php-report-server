<div class="reports-page">
    <div class="page-header">
        <h1>Reports</h1>
        <div class="page-header-actions">
            <button class="btn btn-secondary" onclick="document.getElementById('import-report-input').click()">
                <i class="ph-upload"></i> Import
            </button>
            <input type="file" id="import-report-input" accept=".json" style="display:none" onchange="importReportFile(event)">
            <button class="btn btn-primary" onclick="window.location.href='/reports/new'">
                <i class="ph-plus"></i> New Report
            </button>
        </div>
    </div>
    <table class="table" id="reports-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Description</th>
                <th>Connection</th>
                <th>Updated</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <tr><td colspan="5" class="text-muted">Loading...</td></tr>
        </tbody>
    </table>
</div>
