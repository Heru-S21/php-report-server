<aside class="sidebar">
    <ul class="sidebar-nav">
        <li>
            <a href="/" class="<?= ($content ?? '') === 'dashboard' ? 'active' : '' ?>">
                <i class="ph-layout"></i>
                <span>Dashboard</span>
            </a>
        </li>
        <li>
            <a href="/reports" class="<?= str_starts_with($content ?? '', 'reports') ? 'active' : '' ?>">
                <i class="ph-file-text"></i>
                <span>Reports</span>
            </a>
        </li>
        <li>
            <a href="/connections" class="<?= str_starts_with($content ?? '', 'connections') ? 'active' : '' ?>">
                <i class="ph-database"></i>
                <span>Connections</span>
            </a>
        </li>
    </ul>
</aside>
