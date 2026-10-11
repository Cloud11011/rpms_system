<?php
/** Inspect ZIP metadata without extraction; keep expanded size, entry count and reads bounded. */
function office_container_is_valid(string $path, string $extension, bool $strictWordUpload=false): bool
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
        if ($extension==='docx' && $strictWordUpload) $required[]='_rels/.rels';
        foreach ($required as $name) {
            if (!isset($seen[$name]) || $seen[$name]['size'] < 1) return false;
        }
        if ($extension === 'odt') {
            if ($seen['mimetype']['size'] !== strlen('application/vnd.oasis.opendocument.text')
                || $zip->getFromName('mimetype', 64) !== 'application/vnd.oasis.opendocument.text') return false;
        } elseif ($strictWordUpload) {
            foreach (array_keys($seen) as $name) if (preg_match('~(?:^xl/|^ppt/|(?:^|/)vbaProject\.bin$)~i',$name)) return false;
            $types=office_safe_xml($zip,'[Content_Types].xml',1048576);
            $rels=office_safe_xml($zip,'_rels/.rels',1048576);
            $document=office_safe_xml($zip,'word/document.xml',8*1024*1024);
            if (!$types || !$rels || !$document
                || $types->documentElement->localName!=='Types'
                || $types->documentElement->namespaceURI!=='http://schemas.openxmlformats.org/package/2006/content-types'
                || $rels->documentElement->localName!=='Relationships'
                || $rels->documentElement->namespaceURI!=='http://schemas.openxmlformats.org/package/2006/relationships'
                || $document->documentElement->localName!=='document'
                || !in_array($document->documentElement->namespaceURI,['http://schemas.openxmlformats.org/wordprocessingml/2006/main','http://purl.oclc.org/ooxml/wordprocessingml/main'],true)) return false;
            $main=0;
            foreach ($types->documentElement->childNodes as $node) {
                if (!($node instanceof \DOMElement)) continue;
                $type=$node->getAttribute('ContentType');
                if (preg_match('/macroEnabled|vbaProject/i',$type)) return false;
                if ($node->localName==='Override' && $node->getAttribute('PartName')==='/word/document.xml') {
                    if ($type!=='application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml') return false;
                    ++$main;
                }
            }
            if ($main!==1 || $document->getElementsByTagNameNS($document->documentElement->namespaceURI,'body')->length!==1) return false;
            $main=0;
            foreach ($rels->documentElement->childNodes as $node) {
                if (!($node instanceof \DOMElement)) continue;
                if (in_array($node->getAttribute('Type'),['http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument','http://purl.oclc.org/ooxml/officeDocument/relationships/officeDocument'],true)) {
                    if (!in_array($node->getAttribute('Target'),['word/document.xml','/word/document.xml'],true) || strtolower($node->getAttribute('TargetMode'))==='external') return false;
                    ++$main;
                }
            }
            if ($main!==1) return false;
        }
        return true;
    } finally { $zip->close(); }
}

/** No DTD, entity substitution, network reads or archive extraction; parsing reads are bounded. */
function office_safe_xml(ZipArchive $zip, string $name, int $limit): ?\DOMDocument
{
    $stat=$zip->statName($name);
    if (!$stat || $stat['size']<1 || $stat['size']>$limit) return null;
    $xml=$zip->getFromName($name,$limit);
    if (!is_string($xml) || strlen($xml)!==$stat['size'] || preg_match('/<!DOCTYPE|<!ENTITY/i',$xml)) return null;
    $previous=libxml_use_internal_errors(true);
    try {
        $doc=new \DOMDocument();
        return $doc->loadXML($xml,LIBXML_NONET) && $doc->documentElement ? $doc : null;
    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
}
