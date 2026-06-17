<div class="reports-page">
    <div class="page-header">
        <h1>Reports</h1>
        <div class="report-search-bar" style="display:flex;align-items:center;gap:8px;">
            <input type="text" id="report-search" placeholder="Search reports by name..." class="form-control" style="width:280px;font-size:13px" onkeydown="if(event.key==='Enter') applySearchFilter()">
            <button class="btn btn-secondary btn-sm" onclick="applySearchFilter()"><i class="ph-magnifying-glass"></i></button>
        </div>
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

    <!-- Category Management Bar -->
    <div class="category-bar" id="category-bar">
        <div class="category-add-form">
            <input type="text" id="category-name-input" placeholder="New category name..." class="form-control" style="width:200px;font-size:13px">
            <button class="btn btn-sm btn-primary" onclick="addCategory()"><i class="ph-plus"></i> Add</button>
        </div>
        <div class="category-tabs" id="category-tabs">
            <button class="category-tab active" data-category-id="" onclick="filterByCategory('')">All</button>
        </div>
    </div>

    <!-- Reports Table Wrapper -->
    <div id="reports-container">
        <table class="table" id="reports-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Category</th>
                    <th>Connection</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="6" class="text-muted">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</div>
