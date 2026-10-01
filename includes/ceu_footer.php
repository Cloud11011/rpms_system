<?php
// Presentation only. Each caller has already authenticated the current user.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__
    || !isset($authUser) || !is_array($authUser)
    || !in_array($authUser['role'] ?? '', ['admin', 'adviser', 'student'], true)) {
    http_response_code(404);
    exit;
}
?>
<footer class="ceu-footer" aria-label="Centro Escolar University information">
    <div class="ceu-footer-details">
        <a class="ceu-footer-brand" href="https://www.ceu.edu.ph/" target="_blank" rel="noopener noreferrer" aria-label="Visit the Centro Escolar University website (opens in a new tab)">
            <img src="assets/images/ceu-logo.webp" alt="Centro Escolar University logo" width="256" height="307" loading="lazy" decoding="async">
            <span><strong>Centro Escolar University</strong><small>Malolos Campus</small><span class="ceu-footer-website">Visit the CEU website <span aria-hidden="true">&nearr;</span></span></span>
        </a>
        <section class="ceu-footer-contact" aria-label="CEU Malolos contact information">
            <h2>Contact CEU Malolos</h2>
            <address>
                <p>Km. 44 McArthur Highway,<br>City of Malolos, Bulacan, Philippines</p>
                <p class="ceu-footer-phones"><a href="tel:+63447916359">(044) 791-6359</a><a href="tel:+63447919233">(044) 791-9233</a></p>
            </address>
        </section>
    </div>
    <div class="ceu-footer-bottom"><span>&copy; <?= htmlspecialchars(date('Y'), ENT_QUOTES, 'UTF-8') ?> Centro Escolar University</span><span>PRISM &middot; Research Planning and Monitoring Section</span></div>
</footer>
