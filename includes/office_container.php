<?php
/** Inspect ZIP metadata without extraction; keep expanded size, entry count and reads bounded. */
function office_container_is_valid(string $path, string $extension): bool
{
    if (!in_array($extension, ['docx', 'odt'], true)) return true;
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CHECKCONS) !== true) return false;
    try {
        if ($zip->numFiles < 2 || $zip->numFiles > 4096) return false;
        $expanded = 0; $seen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            if (!$entry || isset($seen[$entry['name']]) || strlen($entry['name']) > 512
                || preg_match('~(?:^/|^[a-z]:|\\\\|(?:^|/)\.\.(?:/|$)|\x00)~i', $entry['name'])
                || !empty($entry['encryption_method']) || $entry['size'] > 40 * 1024 * 1024
                || ($entry['size'] > 1024 * 1024 && $entry['size'] > max(1, $entry['comp_size']) * 200)) return false;
            $seen[$entry['name']] = $entry;
            $expanded += $entry['size'];
            if ($expanded > 100 * 1024 * 1024) return false;
        }
        $required = $extension === 'docx' ? ['[Content_Types].xml', 'word/document.xml'] : ['mimetype', 'content.xml'];
        foreach ($required as $name) {
            if (!isset($seen[$name]) || $seen[$name]['size'] < 1) return false;
        }
        if ($extension === 'odt') {
            if ($seen['mimetype']['size'] !== strlen('application/vnd.oasis.opendocument.text')
                || $zip->getFromName('mimetype', 64) !== 'application/vnd.oasis.opendocument.text') return false;
        }
        return true;
    } finally { $zip->close(); }
}
