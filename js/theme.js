function getTheme() {
    return localStorage.getItem('theme') || 'light';
}

function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('theme', theme);
    const icon = document.querySelector('.theme-toggle i');
    if (icon) {
        icon.className = theme === 'dark' ? 'ph-sun' : 'ph-moon';
    }
}

async function toggleTheme() {
    const current = getTheme();
    const next = current === 'dark' ? 'light' : 'dark';
    applyTheme(next);
    try {
        await window.ReportingEngine.api('PUT', '/api/settings', { theme: next });
    } catch (e) {
        // Silent fail
    }
}

async function syncTheme() {
    try {
        const res = await window.ReportingEngine.api('GET', '/api/settings');
        if (res.success !== false) {
            const serverTheme = res.data?.theme || 'light';
            const localTheme = getTheme();
            if (serverTheme !== localTheme) {
                applyTheme(serverTheme);
            }
        }
    } catch (e) {
        // Use local theme
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const theme = getTheme();
    applyTheme(theme);
    syncTheme();
});
