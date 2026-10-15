<?php
// Presentation only. Each caller has already authenticated the current user.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__
    || !isset($authUser) || !is_array($authUser)
    || !in_array($authUser['role'] ?? '', ['admin', 'adviser', 'student'], true)) {
    http_response_code(404);
    exit;
}
?>
<section class="resources prism-research-resources" data-prism-resources aria-labelledby="prismResourcesTitle">
    <h2 id="prismResourcesTitle">Research Resources</h2>
    <a class="res-row" data-resource="ierb" href="https://ceu-ierb.wixsite.com/ierb" target="_blank" rel="noopener noreferrer">
        <span>IERB Portal</span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M7 17 17 7M8 7h9v9"/>
        </svg>
    </a>
    <div class="res-item">
        <button class="res-row" type="button" aria-expanded="false" aria-controls="prism-sdg-panel">
            <span>Sustainable Development Goals</span>
            <svg class="chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m6 9 6 6 6-6"/>
            </svg>
        </button>
        <div class="res-panel" id="prism-sdg-panel" aria-hidden="true" inert>
            <div>
                <a href="assets/images/sdg.webp" data-prism-resource-preview data-resource-title="Sustainable Development Goals">
                    <img src="assets/images/sdg.webp" alt="The 17 UN Sustainable Development Goals" width="2048" height="1448" loading="lazy" decoding="async">
                </a>
            </div>
        </div>
    </div>
    <div class="res-item">
        <button class="res-row" type="button" aria-expanded="false" aria-controls="prism-agenda-panel">
            <span>Research Agenda</span>
            <svg class="chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m6 9 6 6 6-6"/>
            </svg>
        </button>
        <div class="res-panel" id="prism-agenda-panel" aria-hidden="true" inert>
            <div>
                <a href="assets/images/research-agenda-2023-2028.png" data-prism-resource-preview data-resource-title="Research Agenda">
                    <img class="narrow" src="assets/images/research-agenda-2023-2028.png" alt="CEU Malolos RPMS Research Agenda 2023 to 2028" width="677" height="650" loading="lazy" decoding="async">
                </a>
            </div>
        </div>
    </div>
</section>
<dialog class="prism-resource-preview" id="prismResourcePreview" aria-labelledby="prismResourcePreviewTitle">
    <div class="prism-resource-preview-card">
        <header class="prism-resource-preview-head">
            <span class="prism-resource-preview-brand"><img src="assets/images/prismicon.png" alt="" aria-hidden="true"></span>
            <div><p>PRISM RESEARCH LIBRARY</p><h2 id="prismResourcePreviewTitle">Research resource</h2></div>
            <button type="button" class="prism-resource-preview-close" data-prism-preview-close aria-label="Close preview">&times;</button>
        </header>
        <div class="prism-resource-preview-image-wrap"><img id="prismResourcePreviewImage" alt=""></div>
        <footer class="prism-resource-preview-actions">
            <a id="prismResourcePreviewDownload" class="prism-resource-preview-download" download><i class="fa-solid fa-download" aria-hidden="true"></i> Download image</a>
            <button type="button" data-prism-preview-close>Close</button>
        </footer>
    </div>
</dialog>
