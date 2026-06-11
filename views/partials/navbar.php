<nav class="navbar">
    <div class="navbar-brand">
        <i class="ph-file-text"></i>
        <span>PHP Reporting Engine</span>
    </div>
    <div class="navbar-nav">
        <a href="/" class="nav-link <?= ($content ?? '') === 'dashboard' ? 'active' : '' ?>">
            <i class="ph-layout"></i> Dashboard
        </a>
        <a href="/reports" class="nav-link <?= str_starts_with($content ?? '', 'reports') && !str_starts_with($content ?? '', 'reports/template') && ($content ?? '') !== 'reports/readme' ? 'active' : '' ?>">
            <i class="ph-file-text"></i> Reports
        </a>
        <a href="/connections" class="nav-link <?= str_starts_with($content ?? '', 'connections') ? 'active' : '' ?>">
            <i class="ph-database"></i> Connections
        </a>
        <a href="/templates" class="nav-link <?= str_starts_with($content ?? '', 'reports/template') ? 'active' : '' ?>">
            <i class="ph-bookmark"></i> Templates
        </a>
        <a href="/readme" class="nav-link <?= ($content ?? '') === 'reports/readme' ? 'active' : '' ?>">
            <i class="ph-book-open"></i> Readme
        </a>
        <a href="/settings" class="nav-link <?= ($content ?? '') === 'settings/index' ? 'active' : '' ?>">
            <i class="ph-gear"></i> Settings
        </a>
    </div>
    <div class="navbar-actions">
        <button class="nav-link theme-toggle" onclick="toggleTheme()" title="Toggle theme">
            <i class="ph-moon"></i>
        </button>
        <span class="nav-version">v1.0</span>
        <span id="navbar-user" style="font-size:13px;color:var(--color-text-muted);display:none"></span>
        <a href="#" id="navbar-logout" class="nav-link" style="display:none" onclick="handleLogout()" title="Logout">
            <i class="ph-sign-out"></i>
        </a>
    </div>
</nav>
