<?php // Shared staff-only read interface; authorization is enforced by the hosting page and API. ?>
<?php if (!empty($monitorDashboard)): ?>
<section class="prism-monitor dashboard-progress" data-ierb-monitor data-monitor-layout="cards" data-admin="<?= ($authUser['role']??'')==='admin'?'true':'false' ?>" aria-label="IERB Progress Monitor">
    <header class="dpm-head">
        <div><h2>IERB Progress Monitor</h2><p class="dpm-sub">One record per research project. Times are shown in Asia/Manila.</p></div>
        <div class="dpm-dropdown">
            <button type="button" class="dpm-btn is-primary" data-monitor-menu-toggle="links" aria-controls="dashboardMonitorLinks" aria-expanded="false"><i class="fa-solid fa-list-ul" aria-hidden="true"></i> View all <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>
            <nav class="dpm-menu" id="dashboardMonitorLinks" data-monitor-menu="links" aria-label="More progress views" hidden>
                <a href="ierbprog.php#ierbTableTitle"><i class="fa-solid fa-users" aria-hidden="true"></i> Student records and actions</a>
                <a href="documents.php"><i class="fa-regular fa-file-lines" aria-hidden="true"></i> Open documents</a>
                <a href="ierb_api.php?action=export_csv"><i class="fa-solid fa-download" aria-hidden="true"></i> Export student directory CSV</a>
            </nav>
        </div>
    </header>
    <div class="dpm-panel">
        <div class="dpm-toolbar">
            <label class="dpm-search"><span class="prism-sr-only">Search title, group, name or student ID</span><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" data-monitor-search placeholder="Search title, group, name or student ID"></label>
            <div class="dpm-dropdown">
                <button type="button" class="dpm-btn" data-monitor-menu-toggle="filters" aria-controls="dashboardMonitorFilters" aria-expanded="false"><i class="fa-solid fa-filter" aria-hidden="true"></i> Filters <span class="dpm-filter-count" data-monitor-filter-count hidden>0</span></button>
                <div class="dpm-menu dpm-filters" id="dashboardMonitorFilters" data-monitor-menu="filters" aria-label="Filter and sort" hidden>
                    <h3>Filter &amp; sort</h3>
                    <div class="dpm-filter-grid">
                        <label>Stage<select data-monitor-filter="stage"><option value="">All stages</option></select></label>
                        <label>Academic year<select data-monitor-filter="academicYear"><option value="">All years</option></select></label>
                        <label>Course<select data-monitor-filter="course"><option value="">All courses</option></select></label>
                        <label>Sort by<select data-monitor-sort>
                            <option value="submission:DESC">Newest submission first</option><option value="submission:ASC">Oldest submission first</option>
                            <option value="stage:ASC">Stage</option><option value="title:ASC">Title A–Z</option><option value="title:DESC">Title Z–A</option>
                            <option value="status:ASC">Status</option><option value="studentId:ASC">Student ID</option><option value="academicYear:DESC">Academic year</option>
                        </select></label>
                    </div>
                    <div class="dpm-filter-actions">
                            <div class="dpm-filter-actions-start">
                                <button type="button" class="dpm-btn is-small" data-monitor-reset>Clear all</button>
                                <span class="dpm-filter-divider" aria-hidden="true">|</span>
                                <button type="button" class="dpm-btn is-small" data-monitor-selection-toggle aria-pressed="false">Select records</button>
                            </div>
                            <button type="button" class="dpm-btn is-primary is-small" data-monitor-menu-close>Done</button>
                    </div>
                </div>
            </div>
            <select data-monitor-filter="status" aria-label="Status" hidden><option value="">All statuses</option></select>
        </div>
        <div class="dpm-tabs-row"><div class="dpm-tabs" data-monitor-status-tabs role="group" aria-label="Filter records by status"></div><p class="dpm-count" data-monitor-result-count></p></div>
        <label class="dpm-select-page" data-monitor-select-page hidden><input type="checkbox" data-monitor-page> Select this page</label>
        <div class="dpm-bulk" data-monitor-bulk aria-label="Selected record actions" hidden>
            <div><strong data-monitor-selection-count role="status" aria-live="polite">0 selected</strong><small> · kept across pages and filters (up to 100); Archive and Delete are UI previews only</small></div>
            <div class="dpm-bulk-actions"><button type="button" class="dpm-btn is-small" data-monitor-clear disabled>Clear selection</button><button type="button" class="dpm-btn is-small is-archive" data-monitor-placeholder="archive" aria-label="Archive selected" title="Archive selected" disabled><i class="fa-solid fa-box-archive" aria-hidden="true"></i></button><button type="button" class="dpm-btn is-small is-delete" data-monitor-placeholder="delete" aria-label="Delete selected" title="Delete selected" disabled><i class="fa-solid fa-trash" aria-hidden="true"></i></button><button type="button" class="dpm-btn is-primary is-small" data-monitor-compare disabled>View selected records</button></div>
        </div>
        <p class="dpm-state" data-monitor-state role="status" aria-live="polite">Loading progress records…</p>
        <div class="dpm-list" data-monitor-rows></div>
        <footer class="dpm-foot">
            <label>Rows <select data-monitor-size><option>5</option><option selected>10</option><option>25</option><option>50</option><option>100</option></select></label>
            <div class="dpm-pager" aria-label="Progress pages"><button type="button" data-monitor-prev aria-label="Previous page" disabled>‹</button><span data-monitor-pages></span><span class="prism-sr-only" data-monitor-page-label></span><button type="button" data-monitor-next aria-label="Next page" disabled>›</button></div>
        </footer>
    </div>
</section>
<?php else: ?>
<section class="prism-monitor" data-ierb-monitor data-admin="<?= $authUser['role']==='admin'?'true':'false' ?>" aria-label="IERB Progress Monitor">
    <h2>IERB Progress Monitor</h2>
    <p>One record per research project. Times shown in Asia/Manila.</p>
    <div class="monitor-selection">
        <a class="prism-btn is-secondary" href="ierbprog.php#ierbTableTitle">Student records and actions</a>
        <a class="prism-btn is-secondary" href="documents.php">Open Documents</a>
        <a class="prism-btn is-secondary" href="ierb_api.php?action=export_csv">Export Student directory CSV</a>
    </div>
    <div class="monitor-controls">
        <label>Search research or Student<input type="search" data-monitor-search placeholder="Title, group, name or Student ID"></label>
        <label>Sort<select data-monitor-sort>
            <option value="submission:DESC">Newest submission first</option><option value="submission:ASC">Oldest submission first</option>
            <option value="title:ASC">Research Title A–Z</option><option value="title:DESC">Research Title Z–A</option>
            <option value="stage:ASC">Stage</option><option value="status:ASC">Status</option><option value="studentId:ASC">Student ID</option><option value="academicYear:DESC">Academic Year</option>
        </select></label>
        <label>Stage<select data-monitor-filter="stage"><option value="">All stages</option></select></label>
        <label>Academic year<select data-monitor-filter="academicYear"><option value="">All academic years</option></select></label>
        <label>Course<select data-monitor-filter="course"><option value="">All courses</option></select></label>
        <label>Status<select data-monitor-filter="status"><option value="">All statuses</option></select></label>
        <button type="button" class="prism-btn is-secondary" data-monitor-refresh>Refresh</button>
    </div>
    <?php if ($authUser['role']==='admin'): ?>
    <div class="monitor-selection" aria-label="Read-only record selection">
        <label><input type="checkbox" data-monitor-page> Select current page</label>
        <span data-monitor-selection-count role="status" aria-live="polite">0 selected</span>
        <button type="button" class="prism-btn is-secondary" data-monitor-clear disabled>Clear selection</button>
        <button type="button" class="prism-btn" data-monitor-compare disabled>View selected records</button>
        <small>Selection stays across pages and filters (up to 100 records).</small>
    </div>
    <?php endif; ?>
    <p data-monitor-state role="status" aria-live="polite">Loading progress records…</p>
    <div class="monitor-table-scroll" tabindex="0" role="region" aria-label="Progress records table">
        <table class="monitor-table"><thead><tr>
            <?php if ($authUser['role']==='admin'): ?><th scope="col">Select</th><?php endif; ?>
            <th scope="col">Research / group / Student</th><th scope="col">Official stage</th><th scope="col">Official progress / requirements</th>
            <th scope="col">Pending requirements</th><th scope="col">Latest formal submission</th><th scope="col">Status / correction</th>
            <?php if ($authUser['role']==='admin'): ?><th scope="col">History</th><?php endif; ?>
        </tr></thead><tbody data-monitor-rows></tbody></table>
    </div>
    <div class="monitor-pagination"><button type="button" class="prism-btn is-secondary" data-monitor-prev disabled>Previous</button><span data-monitor-page-label></span><button type="button" class="prism-btn is-secondary" data-monitor-next disabled>Next</button>
        <label>Rows per page<select data-monitor-size><option>10</option><option>25</option><option>50</option><option>100</option></select></label>
    </div>
</section>
<?php endif; ?>
