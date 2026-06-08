<nav class="navbar">
    <div class="navbar-brand">
        <i class="ph-file-text"></i>
        <span>PHP Reporting Engine</span>
    </div>
    <div class="navbar-nav">
        <a href="/" class="nav-link <?= ($content ?? '') === 'dashboard' ? 'active' : '' ?>">
            <i class="ph-layout"></i> Dashboard
        </a>
        <a href="/reports" class="nav-link <?= str_starts_with($content ?? '', 'reports') && ($content ?? '') !== 'reports/readme' ? 'active' : '' ?>">
            <i class="ph-file-text"></i> Reports
        </a>
        <a href="/connections" class="nav-link <?= str_starts_with($content ?? '', 'connections') ? 'active' : '' ?>">
            <i class="ph-database"></i> Connections
        </a>
        <a href="/readme" class="nav-link <?= ($content ?? '') === 'reports/readme' ? 'active' : '' ?>">
            <i class="ph-book-open"></i> Readme
        </a>
    </div>
    <div class="navbar-actions">
        <span class="nav-version">v1.0</span>
    </div>
</nav>
