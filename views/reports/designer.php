<div class="designer-page">
    <div class="designer-toolbar">
        <div class="toolbar-left">
            <button class="btn btn-primary" onclick="designer.save()"><i class="ph-floppy-disk"></i> Save</button>
            <button class="btn" onclick="designer.preview()"><i class="ph-eye"></i> Preview</button>
            <button class="btn" onclick="designer.exportPdf()"><i class="ph-file-pdf"></i> Export PDF</button>
            <button class="btn" onclick="designer.exportHtml()"><i class="ph-file-html"></i> Export HTML</button>
        </div>
        <div class="toolbar-center"></div>
        <div class="toolbar-right">
            <button class="btn btn-icon" onclick="designer.undo()" title="Undo (Ctrl+Z)"><i class="ph-arrow-counter-clockwise"></i></button>
            <button class="btn btn-icon" onclick="designer.redo()" title="Redo (Ctrl+Y)"><i class="ph-arrow-clockwise"></i></button>
            <label class="zoom-label">Zoom:</label>
            <select class="zoom-select" onchange="designer.setZoom(+this.value)">
                <option value="0.5">50%</option>
                <option value="0.75">75%</option>
                <option value="1" selected>100%</option>
                <option value="1.25">125%</option>
            </select>
        </div>
    </div>
    <div class="designer-panels">
        <aside class="panel panel-left">
            <div class="left-tabs">
                <button class="l-tab active" data-lpanel="fields" onclick="switchLeftPanel('fields')" title="Fields"><i class="ph-list"></i><span>Fields</span></button>
                <button class="l-tab" data-lpanel="elements" onclick="switchLeftPanel('elements')" title="Elements"><i class="ph-squares-four"></i><span>Elements</span></button>
                <button class="l-tab" data-lpanel="groups" onclick="switchLeftPanel('groups')" title="Groups"><i class="ph-folder"></i><span>Groups</span></button>
            </div>
            <div class="left-panels">
                <!-- Fields -->
                <div id="lpanel-fields" class="lpanel-content active">
                    <div class="panel-section">
                        <h3><i class="ph-list"></i> Fields</h3>
                        <div id="field-list" class="field-list">
                            <p class="text-muted" style="font-size:12px;padding:4px 0">Run a query to see fields</p>
                        </div>
                    </div>
                </div>
                <!-- Elements -->
                <div id="lpanel-elements" class="lpanel-content">
                    <div class="panel-section">
                        <h3><i class="ph-squares-four"></i> Elements</h3>
                        <div class="element-toolbox">
                            <div class="toolbox-item" draggable="true" data-type="label"><i class="ph-text-t"></i> Label</div>
                            <div class="toolbox-item" draggable="true" data-type="field"><i class="ph-database"></i> Field</div>
                            <div class="toolbox-item" draggable="true" data-type="aggregate"><i class="ph-function"></i> Aggregate</div>
                            <div class="toolbox-item" draggable="true" data-type="image"><i class="ph-image"></i> Image</div>
                            <div class="toolbox-item" draggable="true" data-type="line"><i class="ph-minus"></i> Line</div>
                            <div class="toolbox-item" draggable="true" data-type="rect"><i class="ph-square"></i> Rectangle</div>
                            <div class="toolbox-item" draggable="true" data-type="pageno"><i class="ph-hash"></i> Page #</div>
                            <div class="toolbox-item" draggable="true" data-type="rowno"><i class="ph-list-numbers"></i> Row #</div>
                            <div class="toolbox-item" draggable="true" data-type="datetime"><i class="ph-clock"></i> Date/Time</div>
                        </div>
                    </div>
                </div>
                <!-- Groups -->
                <div id="lpanel-groups" class="lpanel-content">
                    <div class="panel-section">
                        <h3><i class="ph-folder"></i> Groups</h3>
                        <button class="btn btn-sm btn-outline" onclick="groupEditor.open()"><i class="ph-plus"></i> Add Group</button>
                        <ul id="group-list" class="group-list"></ul>
                    </div>
                </div>
            </div>
        </aside>
        <div class="designer-center">
            <div class="center-tabs">
                <button class="c-tab active" data-cpanel="datasource" onclick="switchCenterPanel('datasource')">Data Source</button>
                <button class="c-tab" data-cpanel="designer" onclick="switchCenterPanel('designer')">Report Designer</button>
            </div>
            <div id="cpanel-datasource" class="cpanel-content active">
                <div class="ds-top-row">
                    <div class="ds-col-left">
                        <div class="panel-section">
                            <div class="form-group" style="margin-bottom:6px">
                                <label style="font-size:11px;text-transform:none;letter-spacing:0">Connection</label>
                                <select id="query-connection" class="prop-control" onchange="queryEditor.onConnectionChange()">
                                    <option value="">Select connection...</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom:0">
                                <label style="font-size:11px;text-transform:none;letter-spacing:0">Tables</label>
                                <div id="table-list" class="table-list"><p class="text-muted" style="font-size:12px;padding:2px 0">Select a connection</p></div>
                            </div>
                        </div>
                    </div>
                    <div class="ds-col-right">
                        <div class="panel-section">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                                <label style="font-size:11px;text-transform:none;letter-spacing:0;font-weight:600">Parameters</label>
                                <button class="btn btn-sm btn-outline" onclick="queryEditor.addParameter()" title="Add parameter"><i class="ph-plus"></i></button>
                            </div>
                            <div id="parameter-list"></div>
                        </div>
                    </div>
                </div>
                <div class="ds-query-section">
                    <div class="panel-section">
                        <div class="form-group" style="margin-bottom:6px">
                            <label style="font-size:11px;text-transform:none;letter-spacing:0">SQL Query</label>
                            <textarea id="query-sql" class="prop-control" rows="10" style="font-family:var(--font-mono);font-size:12px;resize:vertical" placeholder="SELECT * FROM ..." onchange="queryEditor.onSqlChange()"></textarea>
                        </div>
                        <div style="display:flex;gap:4px">
                            <button class="btn btn-sm" onclick="queryEditor.runQuery()"><i class="ph-play"></i> Run</button>
                            <button class="btn btn-sm" onclick="queryEditor.applyFields()"><i class="ph-check"></i> Apply Fields</button>
                            <button class="btn btn-sm" onclick="queryEditor.resetQuery()" style="margin-left:auto;color:var(--color-text-muted)"><i class="ph-arrow-counter-clockwise"></i> Reset</button>
                        </div>
                        <div id="query-status" style="font-size:11px;color:var(--color-text-muted);margin-top:4px"></div>
                    </div>
                </div>
                <div id="query-result-table" class="ds-result-section" style="display:none">
                    <div class="panel-section">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                            <label style="font-size:11px;text-transform:none;letter-spacing:0;font-weight:600">Query Results</label>
                            <button class="btn btn-icon btn-sm" onclick="queryEditor.closeResultTable()" title="Close"><i class="ph-x"></i></button>
                        </div>
                        <div id="query-result-body"></div>
                    </div>
                </div>
            </div>
            <div id="cpanel-designer" class="cpanel-content">
                <div class="designer-canvas" id="designer-canvas">
                    <div class="canvas-inner" id="canvas-inner"></div>
                </div>
            </div>
        </div>
        <aside class="panel panel-right">
            <div class="right-panel-tabs">
                <button class="r-tab active" data-rpanel="properties" onclick="switchRightPanel('properties')">Properties</button>
                <button class="r-tab" data-rpanel="tree" onclick="switchRightPanel('tree')">Object Tree</button>
            </div>
            <div id="rpanel-properties" class="rpanel-content active">
                <div class="panel-section">
                    <div class="properties-tabs">
                        <button class="tab active" data-tab="general" onclick="elementEditor.switchTab('general')">General</button>
                        <button class="tab" data-tab="style" onclick="elementEditor.switchTab('style')">Style</button>
                        <button class="tab" data-tab="border" onclick="elementEditor.switchTab('border')">Border</button>
                        <button class="tab" data-tab="advanced" onclick="elementEditor.switchTab('advanced')">Advanced</button>
                    </div>
                    <div id="properties-content" class="properties-content">
                        <p class="text-muted">Select an element or band to edit properties</p>
                    </div>
                </div>
            </div>
            <div id="rpanel-tree" class="rpanel-content">
                <div class="panel-section">
                    <div id="object-tree" class="object-tree">
                        <p class="text-muted" style="font-size:12px;padding:4px 0">No bands</p>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</div>

<!-- Group Editor Modal -->
<div id="group-modal" class="modal" style="display:none">
    <div class="modal-backdrop" onclick="groupEditor.close()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Group</h3>
            <button class="btn btn-icon" onclick="groupEditor.close()"><i class="ph-x"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Group Field</label>
                <select id="group-field-select" class="form-control"></select>
            </div>
            <div class="form-group">
                <label>Sort Direction</label>
                <select id="group-sort-select" class="form-control">
                    <option value="ASC">Ascending</option>
                    <option value="DESC">Descending</option>
                </select>
            </div>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="group-page-break"> Page Break Before
                </label>
            </div>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="group-reprint-header"> Reprint Header on New Page
                </label>
            </div>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="group-reset-rowno"> Reset Row Number per Group
                </label>
            </div>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="group-show-header" checked> Show Header
                </label>
            </div>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="group-show-footer" checked> Show Footer
                </label>
            </div>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="group-collapsed"> Start Collapsed
                </label>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn" onclick="groupEditor.close()">Cancel</button>
            <button class="btn btn-primary" onclick="groupEditor.save()">Save Group</button>
        </div>
    </div>
</div>

<!-- Aggregate Editor Modal -->
<div id="aggregate-modal" class="modal" style="display:none">
    <div class="modal-backdrop" onclick="aggregateEditor.close()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3>Aggregate Settings</h3>
            <button class="btn btn-icon" onclick="aggregateEditor.close()"><i class="ph-x"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Field</label>
                <select id="agg-field-select" class="form-control"></select>
            </div>
            <div class="form-group">
                <label>Function</label>
                <select id="agg-func-select" class="form-control">
                    <option value="sum">SUM</option>
                    <option value="avg">AVG</option>
                    <option value="count">COUNT</option>
                    <option value="min">MIN</option>
                    <option value="max">MAX</option>
                </select>
            </div>
            <div class="form-group">
                <label>Scope</label>
                <select id="agg-scope-select" class="form-control">
                    <option value="group">Group</option>
                    <option value="report">Report</option>
                </select>
            </div>
            <div class="form-group">
                <label>Format</label>
                <input type="text" id="agg-format" class="form-control" placeholder="#,##0.00">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn" onclick="aggregateEditor.close()">Cancel</button>
            <button class="btn btn-primary" onclick="aggregateEditor.save()">Save</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    window.ReportingEngine.state.activeReportId = <?= json_encode($reportId) ?>;
    window.ReportingEngine.state.activeReportGuid = null;
    window.designer = new Designer('canvas-inner');
    window.bandManager = new BandManager(window.designer);
    window.elementEditor = new ElementEditor(window.designer);
    window.groupEditor = new GroupEditor(window.designer);
    window.aggregateEditor = new AggregateEditor(window.designer);
    window.dragDrop = new DragDrop(window.designer);
    window.queryEditor = new QueryEditor(window.designer);
    window.queryEditor.init();
    switchRightPanel('properties');
    switchLeftPanel('fields');
    switchCenterPanel('designer');
});

function switchRightPanel(name) {
    document.querySelectorAll('.r-tab').forEach(t => t.classList.toggle('active', t.dataset.rpanel === name));
    document.querySelectorAll('.rpanel-content').forEach(c => c.classList.toggle('active', c.id === 'rpanel-' + name));
}

function switchLeftPanel(name) {
    document.querySelectorAll('.l-tab').forEach(t => t.classList.toggle('active', t.dataset.lpanel === name));
    document.querySelectorAll('.lpanel-content').forEach(c => c.classList.toggle('active', c.id === 'lpanel-' + name));
}

function switchCenterPanel(name) {
    document.querySelectorAll('.c-tab').forEach(t => t.classList.toggle('active', t.dataset.cpanel === name));
    document.querySelectorAll('.cpanel-content').forEach(c => c.classList.toggle('active', c.id === 'cpanel-' + name));
}
</script>
