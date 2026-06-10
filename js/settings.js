function previewTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    const icon = document.querySelector('.theme-toggle i');
    if (icon) {
        icon.className = theme === 'dark' ? 'ph-sun' : 'ph-moon';
    }
}

async function initSettings() {
    const form = document.getElementById('settings-form');
    const statusEl = document.getElementById('settings-status');

    try {
        const res = await window.ReportingEngine.api('GET', '/api/settings');
        if (res.success !== false) {
            document.getElementById('setting-date_format').value = res.data?.date_format || 'Y-m-d';
            document.getElementById('setting-number_format_decimals').value = res.data?.number_format_decimals ?? 2;
            document.getElementById('setting-number_format_dec_point').value = res.data?.number_format_dec_point || '.';
            document.getElementById('setting-number_format_thousands_sep').value = res.data?.number_format_thousands_sep || ',';
            document.getElementById('setting-theme').value = res.data?.theme || 'light';
            document.getElementById('setting-pdf_engine').value = res.data?.pdf_engine || 'mpdf';
            document.getElementById('setting-max_upload_size').value = res.data?.max_upload_size ? (parseInt(res.data.max_upload_size) / 1048576) : 1;
        }
    } catch (e) {
        // Use defaults
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        statusEl.style.display = 'none';

        const theme = document.getElementById('setting-theme').value;
        const data = {
            date_format: document.getElementById('setting-date_format').value,
            number_format_decimals: document.getElementById('setting-number_format_decimals').value,
            number_format_dec_point: document.getElementById('setting-number_format_dec_point').value || '.',
            number_format_thousands_sep: document.getElementById('setting-number_format_thousands_sep').value || ',',
            theme: theme,
            pdf_engine: document.getElementById('setting-pdf_engine').value,
            max_upload_size: Math.round(parseFloat(document.getElementById('setting-max_upload_size').value || '1') * 1048576),
        };

        try {
            const res = await window.ReportingEngine.api('PUT', '/api/settings', data);
            if (res.success) {
                localStorage.setItem('theme', theme);
                const icon = document.querySelector('.theme-toggle i');
                if (icon) {
                    icon.className = theme === 'dark' ? 'ph-sun' : 'ph-moon';
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
