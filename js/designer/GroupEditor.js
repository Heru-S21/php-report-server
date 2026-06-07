class GroupEditor {
    constructor(designer) {
        this.designer = designer;
        this.modal = document.getElementById('group-modal');
        this.editingGroupId = null;
    }

    open(groupId = null) {
        this.editingGroupId = groupId;
        const groups = window.ReportingEngine.state.definition.groups || [];
        const group = groupId ? groups.find(g => g.id === groupId) : null;

        const fields = window.ReportingEngine.state.queryColumns || [];
        const select = document.getElementById('group-field-select');
        select.innerHTML = fields.map(f =>
            `<option value="${f.name}" ${group && group.fieldName === f.name ? 'selected' : ''}>${f.name}</option>`
        ).join('');

        document.getElementById('group-sort-select').value = group ? group.sortDirection : 'ASC';
        document.getElementById('group-page-break').checked = group ? group.pageBreakBefore : false;
        document.getElementById('group-reprint-header').checked = group ? group.reprintHeaderOnNewPage : false;
        document.getElementById('group-show-header').checked = group ? group.showHeader : true;
        document.getElementById('group-show-footer').checked = group ? group.showFooter : true;
        document.getElementById('group-collapsed').checked = group ? group.startCollapsed : false;

        this.modal.style.display = 'flex';

        // Also open aggregate editor if needed
    }

    close() {
        this.modal.style.display = 'none';
        this.editingGroupId = null;
    }

    save() {
        const fieldName = document.getElementById('group-field-select').value;
        if (!fieldName) { alert('Please select a field'); return; }

        const groups = window.ReportingEngine.state.definition.groups || [];
        if (!window.ReportingEngine.state.definition.groups) {
            window.ReportingEngine.state.definition.groups = [];
        }

        const data = {
            id: this.editingGroupId || 'grp-' + Date.now(),
            fieldName: fieldName,
            level: this.editingGroupId
                ? (groups.find(g => g.id === this.editingGroupId)?.level ?? groups.length)
                : groups.length,
            sortDirection: document.getElementById('group-sort-select').value,
            pageBreakBefore: document.getElementById('group-page-break').checked,
            reprintHeaderOnNewPage: document.getElementById('group-reprint-header').checked,
            showHeader: document.getElementById('group-show-header').checked,
            showFooter: document.getElementById('group-show-footer').checked,
            startCollapsed: document.getElementById('group-collapsed').checked,
        };

        if (this.editingGroupId) {
            const idx = groups.findIndex(g => g.id === this.editingGroupId);
            if (idx !== -1) groups[idx] = data;
        } else {
            groups.push(data);
            if (window.bandManager) {
                window.bandManager.addGroupBands(fieldName, groups.length - 1);
            }
        }

        window.ReportingEngine.dispatch('SET_DEFINITION', window.ReportingEngine.state.definition);
        this.updateGroupList();
        this.close();
    }

    delete(groupId) {
        const groups = window.ReportingEngine.state.definition.groups || [];
        const group = groups.find(g => g.id === groupId);
        if (group && window.bandManager) {
            window.bandManager.removeGroupBands(group.fieldName);
        }
        window.ReportingEngine.state.definition.groups = groups.filter(g => g.id !== groupId);
        this.updateGroupList();
        this.designer.renderCanvas();
    }

    updateGroupList() {
        const list = document.getElementById('group-list');
        const groups = window.ReportingEngine.state.definition.groups || [];
        if (groups.length === 0) {
            list.innerHTML = '<li class="text-muted" style="list-style:none;padding:8px;text-align:center">No groups defined</li>';
            return;
        }
        list.innerHTML = groups.map((g, i) => `
            <li>
                <span>${i + 1}. ${g.fieldName} (${g.sortDirection})</span>
                <span class="group-actions">
                    <button class="btn btn-icon" onclick="window.groupEditor.open('${g.id}')" title="Edit"><i class="ph-pencil"></i></button>
                    <button class="btn btn-icon" onclick="window.groupEditor.delete('${g.id}')" title="Delete"><i class="ph-trash"></i></button>
                </span>
            </li>
        `).join('');
    }
}
