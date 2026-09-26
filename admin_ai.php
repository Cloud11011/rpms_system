<?php
require __DIR__ . '/config.php';
$authUser = require_login(['admin', 'adviser']);
$user_email = $authUser['email'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>AI Workspace | PRISM</title>
    <script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script>
    <link rel="icon" href="assets/images/prismicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/admin-management.css">
    <link rel="stylesheet" href="assets/css/reports.css">
    <link rel="stylesheet" href="assets/css/prism-ui.css">
    <link rel="stylesheet" href="assets/css/workspace-pages.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
</head>
<body class="ai-report-page" data-admin-user="<?php echo htmlspecialchars(hash('sha256', $user_email), ENT_QUOTES); ?>">
<div class="container">
    <aside class="sidebar">
        <div class="sidebar-header"><img src="assets/images/prismlogo1.png?v=2" class="sidebar-brand-logo" alt="PRISM"></div>
        <ul class="nav-links">
            <?php if ($authUser['role'] === 'admin'): ?><li><a href="dashboard.php"><i class="fa-solid fa-chart-line"></i><span>Dashboard</span></a></li><?php endif; ?>
            <li><a href="admin_students.php"><i class="fa-solid fa-user-graduate"></i><span>Students</span></a></li>
            <?php if ($authUser['role'] === 'admin'): ?><li><a href="admin_advisers.php"><i class="fa-solid fa-user-tie"></i><span>Research Advisers</span></a></li><?php endif; ?>
            <li><a href="ierbprog.php"><i class="fa-solid fa-file-signature"></i><span>IERB Progress</span></a></li>
            <li><a href="documents.php"><i class="fa-solid fa-folder-open"></i><span>Documents</span></a></li>
            <li><a href="admin_notifications.php"><i class="fa-solid fa-bell"></i><span>Notifications</span></a></li>
            <li class="active"><a href="admin_ai.php"><i class="fa-solid fa-wand-magic-sparkles"></i><span>AI</span></a></li>
            <li><a href="reports.php"><i class="fa-solid fa-file-pdf"></i><span>Reports</span></a></li>
            <li><a href="calendar.php"><i class="fa-solid fa-calendar-days"></i><span>Calendar</span></a></li>
        </ul>
    </aside>
    <main class="main-content management-page">
        <header class="topbar">
            <div><h1>AI Progress Reports</h1><p>Generate a focused summary or a full progress report. If the AI service is unavailable, PRISM uses its built-in local summarizer.</p></div>
            <div class="theme-toggle" id="themeToggle"><i class="fa-solid fa-sun light-icon"></i><i class="fa-solid fa-moon dark-icon"></i></div>
        </header>

        <section class="report-tools ai-report-tools" aria-label="AI report types">
            <button class="report-tool" id="generateSummarizedReport">
                <span class="report-tool-icon"><i class="fa-solid fa-file-lines"></i></span>
                <span class="report-tool-copy"><span class="report-tool-kicker">Quick overview</span><strong>Generate Summarized Report</strong><small>A concise progress overview of <?php echo $authUser['role'] === 'adviser' ? 'your assigned students' : 'all students'; ?>.</small></span>
                <i class="fa-solid fa-arrow-right"></i>
            </button>
            <button class="report-tool" id="generateFullReport">
                <span class="report-tool-icon"><i class="fa-solid fa-file-contract"></i></span>
                <span class="report-tool-copy"><span class="report-tool-kicker">Complete analysis</span><strong>Generate Full Report</strong><small>A detailed analysis with complete progress information for <?php echo $authUser['role'] === 'adviser' ? 'your assigned students' : 'all students'; ?>.</small></span>
                <i class="fa-solid fa-arrow-right"></i>
            </button>
        </section>
        <p class="report-ai-note" id="reportAiNote" aria-live="polite"></p>

        <section class="management-card ai-history-card">
            <div class="management-card-head"><div><h2>Generated AI Reports</h2><p id="aiHistoryCount">0 reports</p></div></div>
            <div id="aiHistory" class="admin-history"></div>
        </section>

        <?php if (($_SESSION['account_type'] ?? '') === 'admin'): ?>
        <section class="management-card stage-label-card">
            <div class="management-card-head"><div><h2>IERB Stage Labels</h2><p>Set the display name used for each IERB stage throughout PRISM.</p></div></div>
            <div id="stageLabelEditor" class="stage-label-list"></div>
        </section>
        <script>
        (function () {
            const STAGE_ORDER = <?php echo json_encode(STAGE_SEQUENCE); ?>;
            const container = document.getElementById('stageLabelEditor');

            function renderMessage(message) {
                container.replaceChildren();
                const state = document.createElement('div');
                state.className = 'workspace-empty-state';
                const icon = document.createElement('i');
                icon.className = 'fa-solid fa-triangle-exclamation';
                icon.setAttribute('aria-hidden', 'true');
                const text = document.createElement('span');
                text.textContent = message;
                state.append(icon, text);
                container.appendChild(state);
            }

            function renderLabels(labels) {
                container.replaceChildren();
                STAGE_ORDER.forEach((key) => {
                    const row = document.createElement('div');
                    row.className = 'stage-label-row';

                    const keyBlock = document.createElement('div');
                    keyBlock.className = 'stage-key';
                    const keyName = document.createElement('strong');
                    keyName.textContent = key;
                    const keyHelp = document.createElement('span');
                    keyHelp.textContent = 'Stage key';
                    keyBlock.append(keyName, keyHelp);

                    const input = document.createElement('input');
                    input.dataset.stage = key;
                    input.maxLength = 190;
                    input.value = labels[key] || key;
                    input.setAttribute('aria-label', `Display label for ${key}`);

                    const button = document.createElement('button');
                    button.type = 'button';
                    button.dataset.save = key;
                    button.className = 'management-primary';
                    button.textContent = 'Save label';

                    row.append(keyBlock, input, button);
                    container.appendChild(row);
                });
            }

            async function load() {
                try {
                    const res = await fetch('stage_labels_api.php?action=list', { credentials: 'same-origin' });
                    const data = await res.json();
                    if (!res.ok || !data.ok) throw new Error(data.message || 'Could not load stage labels.');
                    renderLabels(data.labels || {});
                } catch (error) {
                    renderMessage(error.message || 'Could not load stage labels.');
                }
            }

            container.addEventListener('click', async (event) => {
                const button = event.target.closest('[data-save]');
                if (!button || button.disabled) return;
                const key = button.dataset.save;
                const input = container.querySelector(`input[data-stage="${key}"]`);
                if (!input) return;
                const label = input.value.trim();
                if (!label) {
                    PrismUI.toast('Enter a display label for this stage.', 'error');
                    input.focus();
                    return;
                }
                button.disabled = true;
                input.disabled = true;
                button.textContent = 'Saving...';
                button.setAttribute('aria-busy', 'true');
                try {
                    const data = await PrismUI.postJson('stage_labels_api.php?action=save', { stageKey: key, label });
                    input.value = data.labels?.[key] || label;
                    PrismUI.toast('Stage label saved.', 'success');
                } catch (error) {
                    PrismUI.toast(error.message, 'error');
                } finally {
                    button.disabled = false;
                    input.disabled = false;
                    button.textContent = 'Save label';
                    button.setAttribute('aria-busy', 'false');
                }
            });

            load();
        })();
        </script>
        <?php endif; ?>
    </main>
</div>
<script src="assets/js/prism-ui.js"></script>
<script src="assets/js/admin-ai.js"></script>
</body>
</html>
