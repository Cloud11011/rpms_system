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
    <div class="ceu-footer-accreditations">
        <div class="ceu-footer-accreditation-art"><img src="assets/images/ceu-accreditations-top.webp" alt="CEU recognitions: CHED full autonomy, AUN-QA certified programs, Philippine Quality Award and Papal Award." width="1980" height="255" loading="lazy" decoding="async"></div>
        <div class="ceu-footer-accreditation-art"><img src="assets/images/ceu-accreditations-bottom.webp" alt="CEU accreditations: ISO 9001, ISO 21001, Times Higher Education sustainability impact ratings and WURI." width="1980" height="255" loading="lazy" decoding="async"></div>
    </div>
    <div class="ceu-footer-details">
        <a class="ceu-footer-brand" href="https://www.ceu.edu.ph/" target="_blank" rel="noopener noreferrer" aria-label="Visit the Centro Escolar University website (opens in a new tab)">
            <img src="assets/images/ceu-logo.webp" alt="Centro Escolar University logo" width="256" height="307" loading="lazy" decoding="async">
            <span><strong>CENTRO ESCOLAR UNIVERSITY</strong><small>Malolos Campus</small></span>
        </a>
        <section class="ceu-footer-contact" aria-label="CEU Malolos contact information">
            <h2>Contact Us</h2>
            <address>
                <p>Km. 44 McArthur Highway,<br>City of Malolos, Bulacan, Philippines</p>
                <p class="ceu-footer-phones"><a href="tel:+63447916359">(044) 791-6359</a><a href="tel:+63447919233">(044) 791-9233</a></p>
            </address>
        </section>
        <section class="ceu-footer-connect" aria-label="Connect with CEU"><h2>Connect with CEU</h2><nav class="ceu-footer-socials" aria-label="CEU social media">
            <a href="https://www.facebook.com/CEUMalolosofficial" target="_blank" rel="noopener noreferrer" aria-label="CEU Malolos on Facebook (opens in a new tab)"><i class="fa-brands fa-facebook-f" aria-hidden="true"></i></a>
            <a href="https://x.com/CEUMalolos" target="_blank" rel="noopener noreferrer" aria-label="CEU Malolos on X (opens in a new tab)"><i class="fa-brands fa-x-twitter" aria-hidden="true"></i></a>
            <a href="https://www.youtube.com/@CEUofficial" target="_blank" rel="noopener noreferrer" aria-label="CEU on YouTube (opens in a new tab)"><i class="fa-brands fa-youtube" aria-hidden="true"></i></a>
        </nav><a class="ceu-footer-website" href="https://www.ceu.edu.ph/" target="_blank" rel="noopener noreferrer">www.ceu.edu.ph <span aria-hidden="true">&nearr;</span></a></section>
    </div>
    <div class="ceu-footer-bottom"><span>&copy; <?= htmlspecialchars(date('Y'), ENT_QUOTES, 'UTF-8') ?> Centro Escolar University</span><span>PRISM &middot; Research Planning and Monitoring Section</span></div>
</footer>
