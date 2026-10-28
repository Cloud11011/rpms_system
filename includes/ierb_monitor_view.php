<?php // Shared staff-only read interface; authorization is enforced by the hosting page and API. ?>
<section class="prism-monitor" data-ierb-monitor data-admin="<?= $authUser['role']==='admin'?'true':'false' ?>" aria-label="IERB Progress Monitor">
    <h2>IERB Progress Monitor</h2>
    <p>One record per canonical project; legacy Student records retain their historical stages. Times shown in Asia/Manila.</p>
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
