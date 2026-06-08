<div class="readme-page">
    <div class="page-header">
        <h1>Readme</h1>
    </div>
    <div class="readme-content" id="readme-content">
        <div class="loading-spinner">Loading...</div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/marked@15.0.7/marked.min.js"></script>
<script>
(async function() {
    try {
        const res = await fetch('/README.md');
        const md = await res.text();
        const html = marked.parse(md);
        document.getElementById('readme-content').innerHTML = html;
    } catch (e) {
        document.getElementById('readme-content').innerHTML = '<div class="error-message">Failed to load README.md</div>';
    }
})();
</script>
