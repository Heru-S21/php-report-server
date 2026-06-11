class DragDrop {
    constructor(designer) {
        this.designer = designer;
        this.initFieldListDrag();
        this.initSortable();
    }

    initFieldListDrag() {
        document.querySelectorAll('.field-item').forEach(item => {
            item.setAttribute('draggable', 'true');
            item.addEventListener('dragstart', (e) => {
                e.dataTransfer.setData('text/plain', 'field');
                e.dataTransfer.setData('field-name', item.dataset.fieldName || '');
                e.dataTransfer.setData('element-width', '50');
                e.dataTransfer.setData('element-height', '10');
            });
        });
    }

    initSortable() {
        const groupList = document.getElementById('group-list');
        if (groupList && window.Sortable) {
            Sortable.create(groupList, {
                animation: 150,
                onEnd: (evt) => {
                    const groups = window.ReportingEngine.state.definition.groups;
                    if (!groups) return;
                    const [removed] = groups.splice(evt.oldIndex, 1);
                    groups.splice(evt.newIndex, 0, removed);
                    groups.forEach((g, i) => g.level = i);
                    window.ReportingEngine.dispatch('SET_DEFINITION', window.ReportingEngine.state.definition);
                    window.groupEditor.updateGroupList();
                    this.designer.renderCanvas();
                }
            });
        }
    }
}
