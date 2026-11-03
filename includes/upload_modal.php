<?php
/*
 * PRISM — Upload documents modal (stage checklist + multiple files)
 *
 * Included by documents.php in place of the old single-file upload modal.
 * Kept on purpose: #uploadModal, #uploadForm, #documentStudent and data-close="uploadModal",
 * because documents.js already opens the modal and fills the Student list with them.
 */
?>
<div class="document-modal" id="uploadModal" aria-hidden="true">
  <div class="document-modal-dialog upc-dialog" role="dialog" aria-modal="true" aria-labelledby="upcTitle">
    <div class="document-modal-heading">
      <div><span>Repository upload</span><h2 id="upcTitle">Upload documents</h2></div>
      <button type="button" data-close="uploadModal" aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>

    <form id="uploadForm" novalidate>
      <div class="upc-top">
        <div class="upc-field" data-upc-student-field>
          <label for="documentStudent">Student</label>
          <select id="documentStudent" name="studentDbId"><option value="">Select a student</option></select>
        </div>
        <div class="upc-field">
          <span class="upc-label" id="upcStageLabel">IERB stage</span>
          <div class="upc-stage" aria-labelledby="upcStageLabel" data-upc-stage>
            <span class="upc-stage-muted">Choose a student first</span>
          </div>
        </div>
      </div>
      <p class="upc-hint">Files always go to the student’s current stage, so you don’t need to pick one.</p>

      <div data-upc-notice role="status" aria-live="polite"></div>

      <section class="upc-checklist" data-upc-checklist hidden aria-labelledby="upcChecklistTitle">
        <div class="upc-section-head">
          <h3 id="upcChecklistTitle">Documents for this stage</h3>
          <span data-upc-checklist-summary></span>
        </div>
        <ul class="upc-req-list" data-upc-req-list></ul>
        <p class="upc-hint" data-upc-checklist-note hidden></p>
      </section>

      <label class="upc-drop" data-upc-drop>
        <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
        <strong>Add several files at once</strong>
        <span>Drop files here or click to browse. PDF or DOCX, up to 20 MB each.<br>PRISM matches each file to a document by its name.</span>
        <input type="file" id="upcFileInput" multiple accept=".pdf,.docx" aria-label="Choose files to upload">
      </label>

      <section class="upc-files" data-upc-files hidden aria-labelledby="upcFilesTitle">
        <h3 id="upcFilesTitle">Files to upload <span data-upc-file-count></span></h3>
        <ul class="upc-file-list" data-upc-file-list></ul>
      </section>

      <div id="uploadResults" class="upc-results" role="status" aria-live="polite" tabindex="-1"></div>

      <div class="upc-footer">
        <span class="upc-footer-status" data-upc-status role="status">Choose a student first.</span>
        <button type="button" class="doc-secondary" data-close="uploadModal">Cancel</button>
        <button type="submit" class="doc-primary" data-upc-submit disabled>
          <i class="fa-solid fa-upload" aria-hidden="true"></i> <span data-upc-submit-label>Upload</span>
        </button>
      </div>
    </form>
  </div>
</div>
