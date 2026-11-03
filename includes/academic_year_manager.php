<section class="academic-year-manager management-card" data-academic-year-manager aria-labelledby="academicYearsTitle">
    <div class="academic-settings-heading">
        <div>
            <p class="academic-settings-eyebrow">Form Configuration</p>
            <h2 id="academicYearsTitle">Academic Years</h2>
        </div>
    </div>
    <p class="academic-settings-current" data-current-year>Loading current academic year…</p>
    <form class="academic-year-manager-form" data-add-year>
        <label for="newAcademicYear">Add academic year</label>
        <div class="academic-year-add-row">
            <input id="newAcademicYear" name="academicYear" type="text" inputmode="numeric" pattern="[1-9][0-9]{3}-[1-9][0-9]{3}" maxlength="9" placeholder="2028-2029" aria-describedby="academicYearHelp" required>
            <button type="submit" class="prism-btn prism-btn-primary">Add academic year</button>
        </div>
        <p id="academicYearHelp">Use consecutive years in YYYY-YYYY format. New years are available for registration; choose “Set current” to identify the active year.</p>
    </form>
    <p class="academic-year-manager-status" data-settings-status role="status" aria-live="polite">Loading academic years…</p>
    <div class="academic-year-table-wrap">
        <table class="academic-year-table">
            <thead><tr><th scope="col">Academic year</th><th scope="col">Status</th><th scope="col">Registration</th><th scope="col">Actions</th></tr></thead>
            <tbody data-year-rows><tr><td colspan="4">Loading academic years…</td></tr></tbody>
        </table>
    </div>
</section>
