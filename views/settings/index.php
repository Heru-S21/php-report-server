<div class="settings-page">
    <div class="page-header">
        <h1><i class="ph-gear"></i> Settings</h1>
    </div>
    <form id="settings-form" class="form">
        <div class="form-card">
            <h3 style="font-size:14px;margin-bottom:16px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.5px">Date & Number Format</h3>
            <div class="form-group">
                <label>Date Format</label>
                <input type="text" id="setting-date_format" class="form-control" value="Y-m-d">
                <small style="color:var(--color-text-muted);font-size:11px;margin-top:2px;display:block">PHP date format string, e.g. <code>Y-m-d</code>, <code>d/m/Y</code>, <code>M j, Y</code></small>
            </div>
            <div class="form-row">
                <div class="form-group flex-1">
                    <label>Decimal Places</label>
                    <input type="number" id="setting-number_format_decimals" class="form-control" min="0" max="10" value="2">
                </div>
                <div class="form-group flex-1">
                    <label>Decimal Separator</label>
                    <input type="text" id="setting-number_format_dec_point" class="form-control" maxlength="1" value="." style="width:80px">
                </div>
                <div class="form-group flex-1">
                    <label>Thousands Separator</label>
                    <input type="text" id="setting-number_format_thousands_sep" class="form-control" maxlength="1" value="," style="width:80px">
                </div>
            </div>
        </div>
        <div class="form-card">
            <h3 style="font-size:14px;margin-bottom:16px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.5px">Image Upload</h3>
            <div class="form-group">
                <label>Max Upload Size (MB)</label>
                <input type="number" id="setting-max_upload_size" class="form-control" min="0.5" max="100" step="0.5" value="1">
                <small style="color:var(--color-text-muted);font-size:11px;margin-top:2px;display:block">Maximum file size for image uploads in megabytes. Affects the image library.</small>
            </div>
        </div>
        <div class="form-card">
            <h3 style="font-size:14px;margin-bottom:16px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.5px">Appearance</h3>
            <div class="form-group">
                <label>Theme</label>
                <select id="setting-theme" class="form-control" onchange="previewTheme(this.value)">
                    <option value="light">Light</option>
                    <option value="dark">Dark</option>
                </select>
                <small style="color:var(--color-text-muted);font-size:11px;margin-top:2px;display:block">Choose appearance. You can also toggle via the <i class="ph-moon" style="font-size:12px"></i> icon in the navbar.</small>
            </div>
        </div>
        <div class="form-card">
            <h3 style="font-size:14px;margin-bottom:16px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.5px">PDF Engine</h3>
            <div class="form-group">
                <label>PDF Engine</label>
                <select id="setting-pdf_engine" class="form-control">
                    <option value="mpdf">mPDF</option>
                </select>
                <small style="color:var(--color-text-muted);font-size:11px;margin-top:2px;display:block">Engine used for PDF export</small>
            </div>
        </div>
        <div class="form-card">
            <h3 style="font-size:14px;margin-bottom:16px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.5px">Authentication</h3>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="setting-auth_enabled">
                    <span style="margin-left:8px">Require login to access the app</span>
                </label>
                <small style="color:var(--color-text-muted);font-size:11px;margin-top:2px;display:block">When enabled, users must log in before accessing any page.</small>
            </div>
            <div class="form-row">
                <div class="form-group flex-1">
                    <label>Username</label>
                    <input type="text" id="setting-auth_username" class="form-control" placeholder="admin" autocomplete="off">
                </div>
                <div class="form-group flex-1">
                    <label>Password</label>
                    <input type="password" id="setting-auth_password" class="form-control" placeholder="Leave blank to keep current" autocomplete="new-password">
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><i class="ph-floppy-disk"></i> Save Settings</button>
        </div>
        <div id="settings-status" class="test-result" style="display:none"></div>
    </form>
</div>
