<?php if (($authUser['role']??'')==='admin'): ?>
<section class="academic-year-manager" data-academic-year-manager aria-label="Academic-year catalog">
    <h2>Academic years</h2><p>Add a year to make it available in all academic selectors. Existing assignments remain as recorded.</p>
    <form><label>New academic year<input name="academicYear" required pattern="[1-9][0-9]{3}-[1-9][0-9]{3}" maxlength="9" placeholder="2028-2029" aria-describedby="academicYearHelp"></label><button type="submit" class="prism-btn">Add academic year</button></form>
    <p id="academicYearHelp">Use consecutive years in YYYY-YYYY format. If additions are unavailable, ask the system operator to enable academic-year management.</p><p role="status" aria-live="polite"></p>
</section>
<?php endif; ?>
