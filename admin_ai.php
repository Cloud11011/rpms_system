<?php require __DIR__ . '/config.php'; $authUser = require_login(['admin','adviser']); $user_email = $authUser['email']; ?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AI Workspace | PRISM Admin</title><script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script><link rel="icon" href="assets/images/prismicon.png"><link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/dashboard.css"><link rel="stylesheet" href="assets/css/admin-management.css"><link rel="stylesheet" href="assets/css/reports.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"></head><body data-admin-user="<?php echo htmlspecialchars(hash('sha256',$user_email),ENT_QUOTES); ?>"><div class="container"><aside class="sidebar"><div class="sidebar-header"><img src="assets/images/prismlogo1.png?v=2" class="sidebar-brand-logo" alt="PRISM"></div><ul class="nav-links"><li><a href="dashboard.php"><i class="fa-solid fa-chart-line"></i><span>Dashboard</span></a></li><li><a href="admin_students.php"><i class="fa-solid fa-user-graduate"></i><span>Students</span></a></li><li><a href="admin_advisers.php"><i class="fa-solid fa-user-tie"></i><span>Research Advisers</span></a></li><li><a href="ierbprog.php"><i class="fa-solid fa-file-signature"></i><span>IERB Progress</span></a></li><li><a href="documents.php"><i class="fa-solid fa-folder-open"></i><span>Documents</span></a></li><li><a href="admin_notifications.php"><i class="fa-solid fa-bell"></i><span>Notifications</span></a></li><li class="active"><a href="admin_ai.php"><i class="fa-solid fa-wand-magic-sparkles"></i><span>AI</span></a></li><li><a href="reports.php"><i class="fa-solid fa-file-pdf"></i><span>Reports</span></a></li><li><a href="calendar.php"><i class="fa-solid fa-calendar-days"></i><span>Calendar</span></a></li></ul></aside><main class="main-content management-page"><header class="topbar"><div><h1>AI Progress Reports</h1><p>Two guardrailed report types only. Each is generated with one click and automatically falls back to a local summarizer if the AI service is unavailable.</p></div><div class="theme-toggle" id="themeToggle"><i class="fa-solid fa-sun light-icon"></i><i class="fa-solid fa-moon dark-icon"></i></div></header>
<section class="report-tools"><button class="report-tool" id="generateSummarizedReport"><span class="report-tool-icon"><i class="fa-solid fa-file-lines"></i></span><span><strong>Generate Summarized Report</strong><small>One click — concise AI overview of all students</small></span><i class="fa-solid fa-bolt"></i></button><button class="report-tool" id="generateFullReport"><span class="report-tool-icon"><i class="fa-solid fa-file-contract"></i></span><span><strong>Generate Full Report</strong><small>One click — AI analysis plus complete per-student detail</small></span><i class="fa-solid fa-bolt"></i></button></section>
<p class="report-ai-note" id="reportAiNote"></p>
<section class="management-card ai-history-card"><div class="management-card-head"><div><h2>Generated AI Reports</h2><p id="aiHistoryCount">0 reports</p></div></div><div id="aiHistory" class="admin-history"></div></section>
<?php if (($_SESSION['account_type'] ?? '') === 'admin'): ?>
<section class="management-card ai-history-card" style="margin-top:16px">
    <div class="management-card-head"><div><h2>IERB Stage Labels</h2><p>Rename what each stage actually means (feature request: dynamic stage labels) -- shown everywhere "Stage 1", "Stage 2" etc. would otherwise appear.</p></div></div>
    <div id="stageLabelEditor" style="padding:0 16px 16px;display:grid;gap:10px"></div>
</section>
<script>
(function () {
    const STAGE_ORDER = <?php echo json_encode(STAGE_SEQUENCE); ?>;
    const container = document.getElementById('stageLabelEditor');

    async function load() {
        const res = await fetch('stage_labels_api.php?action=list');
        const data = await res.json();
        const labels = data.ok ? data.labels : {};
        container.innerHTML = STAGE_ORDER.map(key => `
            <div style="display:grid;grid-template-columns:110px 1fr auto;gap:8px;align-items:center">
                <strong style="font-size:9px;color:var(--text-secondary)">${key}</strong>
                <input data-stage="${key}" value="${(labels[key] || key).replace(/"/g, '&quot;')}"
                    style="padding:8px;border:1px solid var(--border-color);border-radius:6px;background:var(--input-bg);color:var(--text-primary);font:9px Montserrat">
                <button data-save="${key}" class="management-primary" style="padding:8px 12px;font-size:9px">Save</button>
            </div>`).join('');
    }

    container.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-save]');
        if (!btn) return;
        const key = btn.dataset.save;
        const input = container.querySelector(`input[data-stage="${key}"]`);
        btn.disabled = true;
        try {
            const res = await fetch('stage_labels_api.php?action=save', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ stageKey: key, label: input.value.trim() }),
            });
            const data = await res.json();
            if (!data.ok) alert(data.message || 'Could not save this label.');
        } catch (_) {
            alert('Could not reach the server.');
        } finally {
            btn.disabled = false;
        }
    });

    load();
})();
</script>
<?php endif; ?>
</main></div><script src="assets/js/admin-ai.js"></script></body></html>
