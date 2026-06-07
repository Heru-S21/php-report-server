<aside class="sidebar" id="app-sidebar">
    <div class="sidebar-header">
        <button class="sidebar-toggle" id="sidebar-toggle" onclick="toggleSidebar()" title="Toggle sidebar">
            <i class="ph-caret-left"></i>
        </button>
    </div>
    <ul class="sidebar-nav" id="sidebar-nav">
        <li>
            <a href="/" class="<?= ($content ?? '') === 'dashboard' ? 'active' : '' ?>">
                <i class="ph-layout"></i>
                <span class="nav-label">Dashboard</span>
            </a>
        </li>
        <li>
            <a href="/reports" class="<?= str_starts_with($content ?? '', 'reports') ? 'active' : '' ?>">
                <i class="ph-file-text"></i>
                <span class="nav-label">Reports</span>
            </a>
        </li>
        <li>
            <a href="/connections" class="<?= str_starts_with($content ?? '', 'connections') ? 'active' : '' ?>">
                <i class="ph-database"></i>
                <span class="nav-label">Connections</span>
            </a>
        </li>
    </ul>
</aside>
<script>
function toggleSidebar() {
    const sidebar = document.getElementById('app-sidebar');
    const btn = document.getElementById('sidebar-toggle');
    sidebar.classList.toggle('collapsed');
    btn.innerHTML = sidebar.classList.contains('collapsed')
        ? '<i class="ph-caret-right"></i>'
        : '<i class="ph-caret-left"></i>';
}
</script>
