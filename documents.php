<?php
require __DIR__ . '/config.php';
$authUser = require_login(['admin', 'adviser', 'student']);
$user_name = $authUser['full_name'];
$user_email = $authUser['email'];
$user_role = $_SESSION['user_role'] ?? 'RPMS Administrator';
$profile_img = 'assets/images/default-avatar.svg';
$currentRole = $_SESSION['account_type'] ?? 'admin';
if ($authUser['role'] === 'student') {
    header('Location: role_portal.php#documents');
    exit;
}
?>
<!DOCTYPE html><html lang="en"><head>
<?php require __DIR__.'/includes/document_catalog_client.php'; ?>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $authUser['role'] === 'adviser' ? 'Documents to Review' : 'Document Submissions' ?> | PRISM</title>
<script>try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme')}catch(_){}</script>
<link rel="icon" type="image/png" href="assets/images/prismicon.png">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>"><link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/documents.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/prism-ui.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/dashboard-sidebar.css'), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo htmlspecialchars(asset_url('assets/js/dashboard-sidebar.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/ceu-footer.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/prism-workspace.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/batch11.css'), ENT_QUOTES, 'UTF-8') ?>">
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-workspace.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php prism_frontend_styles(); ?>
<link rel="stylesheet" href="<?= htmlspecialchars(asset_url('assets/css/document-records.css'), ENT_QUOTES, 'UTF-8') ?>"></head><body data-prism-allow-demo="<?= defined('APP_ENV') && APP_ENV==='development' && is_loopback_development_request() ? '1' : '0' ?>" class="prism-workspace <?= $authUser['role'] === 'admin' ? 'admin-shell' : 'portal-shell adviser-page' ?>" data-document-user="<?php echo htmlspecialchars(hash('sha256',$user_email),ENT_QUOTES,'UTF-8'); ?>" data-role="<?php echo htmlspecialchars($currentRole, ENT_QUOTES, 'UTF-8'); ?>">
<div class="container"><?php if ($authUser['role'] === 'admin'): ?><aside class="sidebar prism-sidebar"><?php else: ?><header class="portal-navbar"><?php endif; ?>
<?php $prismCurrentPage = 'documents.php'; require __DIR__ . '/includes/prism-navigation.php'; ?>
<?php if ($authUser['role'] === 'admin'): ?></aside><?php else: ?></header><?php endif; ?><main class="main-content documents-page">
<header class="topbar"><div class="documents-heading"><h1><?= $authUser['role'] === 'adviser' ? 'Documents to Review' : 'Document Submissions' ?></h1><p><?= $authUser['role'] === 'adviser' ? 'Review student documents and follow their submission status.' : 'Centralized storage for research and IERB files.' ?></p></div><div class="top-controls"><button type="button" class="theme-toggle" id="themeToggle" title="Toggle light or dark theme" aria-label="Toggle light or dark theme"><i class="fa-solid fa-sun light-icon" aria-hidden="true"></i><i class="fa-solid fa-moon dark-icon" aria-hidden="true"></i></button></div></header>
<?php if ($authUser['role'] === 'adviser'): ?><div data-prism-hint="adviser-documents"></div><?php endif; ?>
<section class="document-controls"><div class="document-search"><i class="fa-solid fa-magnifying-glass"></i><input id="documentSearch" type="search" placeholder="Search file name, student, or document type"></div><div class="document-actions"><select id="documentTypeFilter"><option value="">All document types</option></select><select id="documentCourseFilter"><option value="">All courses</option></select><select id="documentYearFilter"><option value="">All years</option></select><select id="documentSort"><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="name-asc">File name (A–Z)</option><option value="name-desc">File name (Z–A)</option><option value="student-asc">Student (A–Z)</option></select><div class="view-switch" aria-label="Document view"><button class="active" id="tableViewButton" title="Table view"><i class="fa-solid fa-table-list"></i></button><button id="folderViewButton" title="Folder view"><i class="fa-solid fa-folder-tree"></i></button><button id="courseViewButton" title="Group by course"><i class="fa-solid fa-layer-group"></i></button></div><button class="upload-document-button" id="uploadDocumentButton"><i class="fa-solid fa-upload"></i> Upload Document</button></div></section>
<section class="documents-content"><div class="documents-section-heading"><div><h2>Document Repository</h2><p id="documentCount">0 documents</p></div></div>
<div class="documents-table-wrap" id="documentsTableView"><table class="documents-table"><thead><tr><th>File Name</th><th>Uploaded By</th><th>Date Uploaded</th><th>Student Associated</th><th>Category</th><th>Actions</th></tr></thead><tbody id="documentsTableBody"></tbody></table></div>
<div class="folder-view" id="documentsFolderView"></div>
<div class="folder-view" id="documentsCourseView"></div></section>
<?php require __DIR__ . '/includes/ceu_footer.php'; ?>
</main></div>
<div class="document-modal" id="uploadModal" aria-hidden="true"><div class="document-modal-dialog"><div class="document-modal-heading"><div><span>Repository upload</span><h2>Upload Document</h2></div><button data-close="uploadModal" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><form id="uploadForm" enctype="multipart/form-data"><div class="file-drop"><input aria-label="Choose files" aria-describedby="uploadSelection uploadResults" id="documentFile" name="document[]" type="file" multiple accept=".pdf,.docx" required><i class="fa-solid fa-cloud-arrow-up"></i><strong>Choose research documents</strong><span>Accepted formats: PDF and DOCX only, up to 20 MB</span></div><div id="uploadSelection" role="status"></div><div id="uploadResults" role="status" aria-live="polite" tabindex="-1"></div><div class="document-form-grid"><div><label for="documentStudent">Student</label><select id="documentStudent" name="studentDbId" required><option value="">Select a student</option></select></div><div><label for="documentType">Document type</label><div class="document-type-control"><select id="documentType" name="documentType" required></select><button type="button" id="manageDocumentTypes" title="View document catalog" aria-label="View document catalog"><i class="fa-solid fa-pen"></i></button></div></div><div><label for="documentStage">IERB stage</label><select id="documentStage" name="stage" required><option>Stage 1</option><option>Stage 2</option><option>Stage 3</option><option>Stage 4</option><option>Stage 5</option><option>Completed</option></select></div></div><div class="document-modal-actions"><button type="button" class="doc-secondary" data-close="uploadModal">Cancel</button><button type="submit" class="doc-primary"><i class="fa-solid fa-upload"></i> Upload</button></div></form></div></div>
<div class="document-modal" id="documentTypesModal" aria-hidden="true"><div class="document-modal-dialog type-manager-dialog"><div class="document-modal-heading"><div><span>Repository settings</span><h2>Document Types</h2></div><button data-close="documentTypesModal" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><p>New uploads use the PRISM document catalog. Historical document labels remain available in Filters &amp; Sort.</p><ul class="document-type-list" id="documentTypeList"></ul></div></div>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/prism-ui.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/document-upload.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php if ($authUser['role'] === 'admin'): ?>
<div class="document-modal" id="summaryModal" aria-hidden="true"><div class="document-modal-dialog summary-dialog" role="dialog" aria-modal="true" aria-labelledby="summaryHeading">
<div class="document-modal-heading"><div><span>RPMS review aid</span><h2 id="summaryHeading">Document Summary</h2></div><button type="button" data-close="summaryModal" aria-label="Close summary">&times;</button></div>
<p id="summaryFilename" class="summary-filename"></p><p id="summarySource" class="summary-source"></p>
<div id="summaryResult" class="summary-result"></div>
<fieldset class="summary-modes"><legend>Choose a summarization mode</legend>
<label><input type="radio" id="summaryModeAI" name="summaryMode" value="external" checked> <span>AI-Powered Summary<small>Review a minimized excerpt before sending it to OpenRouter.</small></span></label>
<label><input type="radio" id="summaryModeLocal" name="summaryMode" value="local"> <span>Local Summary<small>Research-aware PHP summary. Works offline.</small></span></label>
</fieldset>
<p id="summaryStatus" class="summary-status" role="status" aria-live="polite" aria-busy="false"></p>
<section id="summaryPreviewPanel" class="summary-preview" aria-label="Review outgoing research excerpt" hidden>
<h3>Review the exact outgoing excerpt</h3><p>Only the text below will be sent to OpenRouter. Check for remaining names, confidential information, and details that could identify participants. Use Local Summary if external processing is restricted or institutional permission is uncertain. OpenRouter and its model provider process the excerpt under their own policies.</p>
<p id="summaryOmitted" class="summary-source"></p><pre id="summaryExcerpt" class="summary-excerpt" tabindex="0"></pre>
<label class="summary-confirmation"><input type="checkbox" id="summaryAuthorization"> <span>I have reviewed the excerpt and confirm that I am authorized to send this information to OpenRouter for AI summarization.</span></label>
<div class="document-modal-actions"><button type="button" class="doc-secondary" id="cancelSummaryPreview">Cancel Excerpt</button><button type="button" class="doc-primary" id="sendSummary" disabled>Generate AI Summary</button></div>
</section>
<p class="summary-review-note">Verify important facts against the original document. Summaries do not replace RPMS/IERB professional judgment or grant institutional or ethics approval.</p>
<div class="document-modal-actions summary-main-actions"><button type="button" class="doc-secondary" data-close="summaryModal">Close</button><button type="button" class="doc-secondary" id="localSummary" hidden>Generate Local Summary Instead</button><button type="button" class="doc-primary" id="regenerateSummary">Review AI Excerpt</button></div>
</div></div><?php endif; ?>
<script src="<?php echo htmlspecialchars(asset_url('assets/js/documents.js'), ENT_QUOTES, 'UTF-8'); ?>"></script></body></html>
