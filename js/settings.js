function previewTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    const icon = document.querySelector('.theme-toggle i');
    if (icon) {
        icon.className = theme === 'dark' ? 'ph-sun' : 'ph-moon';
    }
}

function buildSettingField(def, value) {
    const group = document.createElement('div');
    group.className = 'form-group';

    const label = document.createElement('label');
    label.htmlFor = 'setting-' + def.key;
    label.textContent = def.label;
    group.appendChild(label);

    let input;
    switch (def.type) {
        case 'bool': {
            const wrap = document.createElement('label');
            wrap.className = 'checkbox-label';
            input = document.createElement('input');
            input.type = 'checkbox';
            input.id = 'setting-' + def.key;
            input.checked = value === '1' || value === 'true' || value === true;
            wrap.appendChild(input);
            const span = document.createElement('span');
            span.style.marginLeft = '8px';
            span.textContent = def.label;
            // Replace label text with just the checkbox
            label.textContent = '';
            wrap.appendChild(span);
            group.removeChild(label);
            group.appendChild(wrap);
            break;
        }
        case 'int': {
            input = document.createElement('input');
            input.type = 'number';
            input.id = 'setting-' + def.key;
            input.className = 'form-control';
            if (def.key === 'number_format_decimals') {
                input.min = '0';
                input.max = '10';
            } else if (def.key === 'max_upload_size') {
                input.min = '0.5';
                input.max = '100';
                input.step = '0.5';
                // Convert bytes to MB for display
                value = parseInt(value) / 1048576;
            }
            input.value = value;
            group.appendChild(input);
            break;
        }
        case 'select': {
            input = document.createElement('select');
            input.id = 'setting-' + def.key;
            input.className = 'form-control';
            for (const [optVal, optLabel] of Object.entries(def.options || {})) {
                const opt = document.createElement('option');
                opt.value = optVal;
                opt.textContent = optLabel;
                if (String(value) === String(optVal)) {
                    opt.selected = true;
                }
                input.appendChild(opt);
            }
            if (def.key === 'theme') {
                input.addEventListener('change', function () {
                    previewTheme(this.value);
                });
            }
            group.appendChild(input);
            break;
        }
        case 'password': {
            input = document.createElement('input');
            input.type = 'password';
            input.id = 'setting-' + def.key;
            input.className = 'form-control';
            input.placeholder = 'Leave blank to keep current';
            input.autocomplete = 'new-password';
            input.value = '';
            group.appendChild(input);
            break;
        }
        default: {
            // string
            input = document.createElement('input');
            input.type = 'text';
            input.id = 'setting-' + def.key;
            input.className = 'form-control';
            input.value = value;
            group.appendChild(input);
            break;
        }
    }

    if (def.description && def.type !== 'bool') {
        const small = document.createElement('small');
        small.style.cssText = 'color:var(--color-text-muted);font-size:11px;margin-top:2px;display:block';
        small.innerHTML = def.description.replace(/`([^`]+)`/g, '<code>$1</code>');
        group.appendChild(small);
    }

    return group;
}

async function initSettings() {
    const form = document.getElementById('settings-form');
    const container = document.getElementById('settings-container');
    const actions = document.getElementById('settings-actions');
    const statusEl = document.getElementById('settings-status');

    let definitions = [];
    try {
        const res = await window.ReportingEngine.api('GET', '/api/settings');
        if (res.success === false) {
            container.innerHTML = '<p style="color:var(--color-error);text-align:center;padding:40px 0">Failed to load settings: ' + (res.message || 'Unknown error') + '</p>';
            return;
        }

        definitions = res.data?.definitions || [];
        const values = res.data?.values || {};

        // Group by group name
        const groups = {};
        for (const def of definitions) {
            const g = def.group || 'Other';
            if (!groups[g]) groups[g] = [];
            groups[g].push(def);
        }

        // Build form
        container.innerHTML = '';
        for (const [groupName, groupDefs] of Object.entries(groups)) {
            const card = document.createElement('div');
            card.className = 'form-card';

            const heading = document.createElement('h3');
            heading.style.cssText = 'font-size:14px;margin-bottom:16px;color:var(--color-text-muted);text-transform:uppercase;letter-spacing:0.5px';
            heading.textContent = groupName;
            card.appendChild(heading);

            if (groupName === 'Image Upload') {
                // Row layout for max_upload_size
                const row = document.createElement('div');
                row.className = 'form-row';
                for (const def of groupDefs) {
                    const field = buildSettingField(def, values[def.key] ?? def.default);
                    field.className = 'flex-1';
                    row.appendChild(field);
                }
                card.appendChild(row);
            } else if (groupName === 'Report Default Margins') {
                // 4-column row
                const row = document.createElement('div');
                row.className = 'form-row';
                for (const def of groupDefs) {
                    const field = buildSettingField(def, values[def.key] ?? def.default);
                    field.className = 'flex-1';
                    row.appendChild(field);
                }
                card.appendChild(row);
            } else if (groupName === 'Authentication') {
                for (const def of groupDefs) {
                    if (def.type === 'bool') {
                        card.appendChild(buildSettingField(def, values[def.key] ?? def.default));
                    }
                }
                // Username + Password row
                const row = document.createElement('div');
                row.className = 'form-row';
                for (const def of groupDefs) {
                    if (def.type !== 'bool') {
                        const field = buildSettingField(def, values[def.key] ?? def.default);
                        field.className = 'flex-1';
                        row.appendChild(field);
                    }
                }
                card.appendChild(row);
            } else if (groupName === 'Date & Number Format') {
                let first = true;
                for (const def of groupDefs) {
                    if (first && def.type === 'string') {
                        // date_format full width
                        card.appendChild(buildSettingField(def, values[def.key] ?? def.default));
                        first = false;
                    } else {
                        // decimals, dec_point, thousands_sep in row
                        const row = document.createElement('div');
                        row.className = 'form-row';
                        // Collect remaining defs
                        const remaining = groupDefs.slice(1);
                        for (const rd of remaining) {
                            const field = buildSettingField(rd, values[rd.key] ?? rd.default);
                            field.className = 'flex-1';
                            row.appendChild(field);
                        }
                        card.appendChild(row);
                        break;
                    }
                }
            } else {
                // Default: one field per row
                for (const def of groupDefs) {
                    card.appendChild(buildSettingField(def, values[def.key] ?? def.default));
                }
            }

            container.appendChild(card);
        }

        actions.style.display = '';

        // Sync theme select with localStorage (user's active preference)
        const themeSelect = document.getElementById('setting-theme');
        if (themeSelect) {
            themeSelect.value = localStorage.getItem('theme') || 'light';
        }

    } catch (e) {
        container.innerHTML = '<p style="color:var(--color-error);text-align:center;padding:40px 0">Error loading settings: ' + e.message + '</p>';
        return;
    }

    // Submit handler
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        statusEl.style.display = 'none';

        const data = {};

        for (const def of definitions) {
            const el = document.getElementById('setting-' + def.key);
            if (!el) continue;

            switch (def.type) {
                case 'bool':
                    data[def.key] = el.checked ? '1' : '0';
                    break;
                case 'int':
                    if (def.key === 'max_upload_size') {
                        // Convert MB back to bytes
                        data[def.key] = Math.round(parseFloat(el.value || '1') * 1048576);
                    } else {
                        data[def.key] = el.value;
                    }
                    break;
                case 'password':
                    if (el.value) {
                        data[def.key] = el.value;
                    }
                    break;
                default:
                    data[def.key] = el.value;
                    break;
            }
        }

        try {
            const res = await window.ReportingEngine.api('PUT', '/api/settings', data);
            if (res.success) {
                const themeEl = document.getElementById('setting-theme');
                if (themeEl) {
                    localStorage.setItem('theme', themeEl.value);
                }
            }
            statusEl.className = 'test-result ' + (res.success ? 'success' : 'error');
            statusEl.textContent = res.success ? 'Settings saved successfully' : (res.message || 'Failed to save settings');
            statusEl.style.display = 'block';
        } catch (err) {
            statusEl.className = 'test-result error';
            statusEl.textContent = 'Error saving settings';
            statusEl.style.display = 'block';
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    if (window.location.pathname === '/settings') {
        initSettings();
    }
});
