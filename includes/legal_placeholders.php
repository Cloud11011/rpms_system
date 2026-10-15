<?php
// Centralized, conspicuous owner-supplied placeholders. Replace with approved values before launch.
require_once __DIR__.'/public_urls.php';
return array_replace(array_fill_keys([
    '[SUPPORT/PRIVACY EMAIL]', '[PRIVACY CONTACT / DATA PROTECTION OFFICER]', '[EMAIL ADDRESS]', '[NAME]',
    '[PUBLIC PRIVACY POLICY URL]', '[ORGANIZATION / PRISM OPERATOR]', '[SUPPORT EMAIL]',
    '[ORGANIZATION / OPERATOR]', '[PUBLIC TERMS URL]',
], null), [
    '[PUBLIC PRIVACY POLICY URL]' => prism_public_legal_url('privacy'),
    '[PUBLIC TERMS URL]' => prism_public_legal_url('terms'),
]);
