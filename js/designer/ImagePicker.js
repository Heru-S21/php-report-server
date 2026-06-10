class ImagePicker {
    constructor() {
        this.callback = null;
        this.images = [];
        this.selectedId = null;
        this.init();
    }

    init() {
        document.getElementById('image-picker-select-btn')?.addEventListener('click', () => this.select());
        document.getElementById('image-picker-close')?.addEventListener('click', () => this.close());
        document.getElementById('image-picker-modal')?.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) this.close();
        });
        document.getElementById('image-picker-upload-btn')?.addEventListener('click', () => {
            document.getElementById('image-picker-file').click();
        });
        document.getElementById('image-picker-file')?.addEventListener('change', (e) => this.upload(e));
    }

    open(callback) {
        this.callback = callback;
        this.selectedId = null;
        document.getElementById('image-picker-modal').style.display = 'flex';
        document.getElementById('image-picker-select-btn').disabled = true;
        this.loadImages();
    }

    close() {
        document.getElementById('image-picker-modal').style.display = 'none';
        this.callback = null;
    }

    async loadImages() {
        const grid = document.getElementById('image-picker-grid');
        grid.innerHTML = '<p class="text-muted">Loading...</p>';
        try {
            const res = await window.ReportingEngine.api('GET', '/api/images');
            this.images = res.data || [];
            this.render();
        } catch (e) {
            grid.innerHTML = '<p class="text-muted">Failed to load images</p>';
        }
    }

    render() {
        const grid = document.getElementById('image-picker-grid');
        if (this.images.length === 0) {
            grid.innerHTML = '<p class="text-muted">No images uploaded yet. Click Upload to add one.</p>';
            return;
        }
        grid.innerHTML = this.images.map(img => {
            const src = `/api/images/file/${img.guid}`;
            const size = (img.file_size / 1024).toFixed(1) + ' KB';
            const dims = img.width && img.height ? `${img.width} × ${img.height}` : '';
            const sel = this.selectedId === img.id ? ' selected' : '';
            return `
                <div class="image-picker-item${sel}"
                     data-id="${img.id}" data-guid="${img.guid}" data-url="${src}"
                     onclick="window.imagePicker.pick(${img.id})">
                    <div class="image-picker-thumb">
                        <img src="${src}" alt="${escapeHtml(img.original_name)}" loading="lazy">
                    </div>
                    <div class="image-picker-info">
                        <span class="image-picker-name" title="${escapeHtml(img.original_name)}">${escapeHtml(img.original_name)}</span>
                        <span class="image-picker-meta">${size}${dims ? ' · ' + dims : ''}</span>
                    </div>
                </div>
            `;
        }).join('');
    }

    pick(id) {
        this.selectedId = id;
        document.querySelectorAll('.image-picker-item').forEach(el => {
            el.classList.toggle('selected', parseInt(el.dataset.id) === id);
        });
        document.getElementById('image-picker-select-btn').disabled = false;
    }

    select() {
        if (!this.selectedId || !this.callback) return;
        const item = document.querySelector(`.image-picker-item[data-id="${this.selectedId}"]`);
        if (!item) return;
        try {
            this.callback(item.dataset.url, item.dataset.guid);
        } catch (e) {
            console.error('ImagePicker callback error:', e);
        }
        this.close();
    }

    async upload(event) {
        const fileInput = event.target;
        const file = fileInput.files[0];
        if (!file) return;

        const statusEl = document.getElementById('image-picker-status');
        statusEl.textContent = 'Uploading...';
        statusEl.className = 'image-picker-status';

        const formData = new FormData();
        formData.append('file', file);

        try {
            const res = await fetch('/api/images/upload', {
                method: 'POST',
                body: formData,
            });
            const data = await res.json();
            if (data.success) {
                statusEl.textContent = 'Upload successful';
                statusEl.className = 'image-picker-status success';
                fileInput.value = '';
                await this.loadImages();
            } else {
                statusEl.textContent = data.message || 'Upload failed';
                statusEl.className = 'image-picker-status error';
            }
        } catch (e) {
            statusEl.textContent = 'Upload failed: ' + e.message;
            statusEl.className = 'image-picker-status error';
        }
    }
}
