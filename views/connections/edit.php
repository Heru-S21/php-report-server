<div class="connection-edit-page">
    <div class="page-header">
        <h1><?= $connectionId ? 'Edit Connection' : 'New Connection' ?></h1>
        <a href="/connections" class="btn"><i class="ph-arrow-left"></i> Back</a>
    </div>
    <form id="connection-form" class="form">
        <input type="hidden" id="conn-id" value="<?= htmlspecialchars($connectionId ?? '') ?>">
        <div class="form-card">
            <div class="form-group">
                <label>Connection Name *</label>
                <input type="text" id="conn-name" class="form-control" required placeholder="My Database">
            </div>
            <div class="form-group">
                <label>Driver *</label>
                <select id="conn-driver" class="form-control" required onchange="toggleConnectionFields()">
                    <option value="">Select driver...</option>
                    <option value="sqlite">SQLite</option>
                    <option value="mysql">MySQL</option>
                    <option value="mssql">MS SQL Server</option>
                    <option value="pgsql">PostgreSQL</option>
                </select>
            </div>
            <div id="connection-host-fields" style="display:none">
                <div class="form-row">
                    <div class="form-group flex-3">
                        <label>Host</label>
                        <input type="text" id="conn-host" class="form-control" placeholder="localhost">
                    </div>
                    <div class="form-group flex-1">
                        <label>Port</label>
                        <input type="number" id="conn-port" class="form-control" placeholder="3306">
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>Database / File Path *</label>
                <input type="text" id="conn-database" class="form-control" required placeholder="database_name or /path/to/file.db">
            </div>
            <div id="connection-auth-fields" style="display:none">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="conn-username" class="form-control">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" id="conn-password" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label>Options (JSON)</label>
                <textarea id="conn-options" class="form-control" rows="3" placeholder='{"charset":"utf8mb4"}'></textarea>
            </div>
        </div>
        <div class="form-actions">
            <button type="button" class="btn" onclick="testConnection()"><i class="ph-plug"></i> Test Connection</button>
            <button type="submit" class="btn btn-primary"><i class="ph-floppy-disk"></i> Save Connection</button>
        </div>
        <div id="connection-test-result" class="test-result" style="display:none"></div>
    </form>
</div>
