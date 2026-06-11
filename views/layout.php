<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Reporting Engine</title>
    <link rel="stylesheet" href="/css/app.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="https://unpkg.com/phosphor-icons@1.4.2/src/css/icons.css">
    <?php if (isset($extraCss)): ?>
        <?php foreach ((array)$extraCss as $css): ?>
            <link rel="stylesheet" href="<?= htmlspecialchars($css) ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <script>document.documentElement.setAttribute('data-theme',localStorage.getItem('theme')||'light')</script>
    <style>
        @media print {
            .navbar { display: none !important; }
            .preview-toolbar { display: none !important; }
            .main-content { padding: 0 !important; }
            .page-header { display: none !important; }
            .preview-params { display: none !important; }
            .preview-paper { background: none !important; padding: 0 !important; overflow: visible !important; }
            .preview-ruler { display: none !important; }
            .preview-container { width: auto !important; }
            .preview-container .report-page { box-shadow: none !important; margin: 0 auto !important; }
            .paper-page { box-shadow: none !important; margin: 0 auto !important; padding: 0 !important; }
        }
    </style>
</head>
<body>
    <?php if (!isset($showNavbar) || $showNavbar): ?>
        <?php include __DIR__ . '/partials/navbar.php'; ?>
    <?php endif; ?>
    <?php
    $contentBefore = $contentBefore ?? '';
    $pageContent = '';
    if (isset($content) && $content) {
        $viewFile = __DIR__ . '/' . $content . '.php';
        if (file_exists($viewFile)) {
            ob_start();
            include $viewFile;
            $pageContent = ob_get_clean();
        } else {
            $pageContent = '<div class="error-message">View not found: ' . htmlspecialchars($content) . '</div>';
        }
    }
    ?>
    <?= $contentBefore ?>
    <main class="main-content">
            <?= $pageContent ?>
        </main>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/6.65.7/codemirror.min.js"></script>
    <script src="/js/app.js"></script>
    <script src="/js/theme.js"></script>
    <?php if (isset($extraScripts)): ?>
        <?php foreach ((array)$extraScripts as $script): ?>
            <script src="<?= htmlspecialchars($script) ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
