<?php
// Presentation only. Each caller has already authenticated the current user.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__
    || !isset($authUser) || !is_array($authUser)
    || !in_array($authUser['role'] ?? '', ['admin', 'adviser', 'student'], true)) {
    http_response_code(404);
    exit;
}
?>
<section class="prism-research-resources" data-prism-resources aria-labelledby="prismResourcesTitle">
    <header class="prism-resources-heading"><h2 id="prismResourcesTitle">Research resources</h2><p>Browse the Sustainable Development Goals and CEU Malolos Research Agenda.</p></header>
    <div class="prism-resource-grid">
        <details class="prism-resource" data-resource="sdg">
            <summary><span class="prism-resource-title">Sustainable Development Goals</span><span class="prism-resource-subtitle">17 global goals</span></summary>
            <div class="prism-resource-content">
                <figure>
                    <div class="prism-resource-image"><img src="assets/images/sdg.webp" alt="The 17 United Nations Sustainable Development Goals, also listed below." width="2048" height="1448" loading="lazy" decoding="async"></div>
                    <figcaption>United Nations &middot; 17 Sustainable Development Goals</figcaption>
                </figure>
                <a class="prism-resource-open" data-resource-open href="assets/images/sdg.webp" target="_blank" rel="noopener noreferrer">Open SDG image at full size <span>(new tab)</span></a>
                <div class="prism-resource-transcript">
                    <h3>The 17 goals</h3>
                    <ol>
                        <li>No Poverty</li><li>Zero Hunger</li><li>Good Health and Well-being</li><li>Quality Education</li><li>Gender Equality</li><li>Clean Water and Sanitation</li><li>Affordable and Clean Energy</li><li>Decent Work and Economic Growth</li><li>Industry, Innovation and Infrastructure</li><li>Reduced Inequalities</li><li>Sustainable Cities and Communities</li><li>Responsible Consumption and Production</li><li>Climate Action</li><li>Life Below Water</li><li>Life on Land</li><li>Peace, Justice and Strong Institutions</li><li>Partnerships for the Goals</li>
                    </ol>
                </div>
            </div>
        </details>
        <details class="prism-resource" data-resource="agenda">
            <summary><span class="prism-resource-title">Research Agenda</span><span class="prism-resource-subtitle">CEU Malolos &middot; 2023&ndash;2028</span></summary>
            <div class="prism-resource-content">
                <figure>
                    <div class="prism-resource-image"><img src="assets/images/research-matrix.webp" alt="CEU Malolos Research Agenda 2023–2028: six research clusters and their Sustainable Development Goal mappings, also listed below." width="612" height="786" loading="lazy" decoding="async"></div>
                    <figcaption>CEU Malolos &middot; Research Agenda 2023&ndash;2028</figcaption>
                </figure>
                <a class="prism-resource-open" data-resource-open href="assets/images/research-matrix.webp" target="_blank" rel="noopener noreferrer">Open Research Agenda image at full size <span>(new tab)</span></a>
                <div class="prism-resource-transcript">
                    <h3>Research clusters and SDGs</h3>
                    <ol>
                        <li><strong>Health Science (Health and Wellness)</strong><span>SDGs 2, 3 and 9</span></li>
                        <li><strong>Social Science and Humanities (Intercultural Ethics)</strong><span>SDGs 1, 5, 10 and 16</span></li>
                        <li><strong>Education (Quality Teaching and Learning)</strong><span>SDG 4</span></li>
                        <li><strong>Business and Hospitality Management (CSR and Entrepreneurship)</strong><span>SDGs 8 and 12</span></li>
                        <li><strong>Environmental Research (Climate Change Mitigation, Biodiversity, and Ecosystem Conservation)</strong><span>SDGs 6, 7, 12, 13, 14 and 15</span></li>
                        <li><strong>Institutional Research</strong><span>SDGs 10, 11, 13, 16 and 17</span></li>
                    </ol>
                </div>
            </div>
        </details>
    </div>
</section>
