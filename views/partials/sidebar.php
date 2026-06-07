<aside class="sidebar" id="app-sidebar">
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
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('app-sidebar');
    if (localStorage.getItem('sidebarCollapsed') === 'true') {
        sidebar.classList.add('collapsed');
    }
});
function toggleSidebar() {
    const sidebar = document.getElementById('app-sidebar');
    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
}
</script>
