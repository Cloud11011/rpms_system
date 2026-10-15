<?php
/** Defensive transport/file validation. No application bootstrap or persistent state. */
class DocumentUploadError extends RuntimeException
{
    public function __construct(string $message, public int $status = 422) { parent::__construct($message); }
}

function allowed_document_extensions(): array
{
    return ['pdf','docx'];
}

function prism_pdf_content_is_valid(string $path): bool
{
    $handle=fopen($path,'rb');
    if (!$handle) return false;
    try {
        $start=fread($handle,65536);
        if (!preg_match('/\A%PDF-[12]\.[0-9](?:\r\n|\r|\n)/',$start)) return false;
        if (!preg_match('/\b[0-9]+\s+[0-9]+\s+obj\b/',$start)) return false;
        fseek($handle,max(0,filesize($path)-2048));
        $tail=stream_get_contents($handle);
        return (bool)preg_match('/startxref\s+[0-9]+\s+%%EOF\s*\z/s',$tail);
    } finally { fclose($handle); }
}

function prism_upload_files(mixed $input): array
{
    $fields = ['name', 'tmp_name', 'error', 'size', 'type'];
    if (!is_array($input) || array_diff($fields, array_keys($input))) {
        throw new DocumentUploadError('The file selection is malformed. Choose the files again.', 400);
    }
    $multiple = is_array($input['name']);
    $keys = $multiple ? array_keys($input['name']) : [0];
    if (!$keys || ($multiple && $keys !== range(0, count($keys) - 1))) {
        throw new DocumentUploadError('The file selection is malformed. Choose the files again.', 400);
    }
    foreach ($fields as $field) {
        if (is_array($input[$field]) !== $multiple || ($multiple && array_keys($input[$field]) !== $keys)) {
            throw new DocumentUploadError('The file selection is malformed. Choose the files again.', 400);
        }
    }
    $limit = (int)ini_get('max_file_uploads');
    if ($limit > 0 && count($keys) > $limit) {
        throw new DocumentUploadError('The selection exceeds this server’s PHP upload limit. Select fewer files.', 400);
    }
    $files = [];
    foreach ($keys as $i) {
        $file = [];
        foreach ($fields as $field) $file[$field] = $multiple ? $input[$field][$i] : $input[$field];
        if (!is_string($file['name']) || !is_string($file['tmp_name']) || !is_string($file['type'])
            || !is_int($file['error']) || !is_int($file['size']) || $file['size'] < 0) {
            throw new DocumentUploadError('The file selection is malformed. Choose the files again.', 400);
        }
        $files[] = $file;
    }
    return $files;
}

function prism_validate_upload(array $file): array
{
    $errors = [UPLOAD_ERR_INI_SIZE => 'The file exceeds this server’s upload size limit.',
        UPLOAD_ERR_FORM_SIZE => 'The file exceeds the allowed upload size.',
        UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Choose the file again.',
        UPLOAD_ERR_NO_FILE => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'The file could not be uploaded. Please try again later.',
        UPLOAD_ERR_CANT_WRITE => 'The file could not be uploaded. Please try again later.',
        UPLOAD_ERR_EXTENSION => 'The server could not accept this file.'];
    if ($file['error'] !== UPLOAD_ERR_OK) throw new DocumentUploadError($errors[$file['error']] ?? 'The upload did not complete.', 400);
    if ($file['name'] === '' || preg_match('~[\x00-\x1f\x7f/\\\\]~', $file['name'])
        || mb_strlen($file['name']) > 255 || preg_match('/\.(?:php[0-9]*|phtml|phar|html?|js|exe|com|bat|cmd|sh|cgi|pl)(?:\.|$)/i', $file['name'])) {
        throw new DocumentUploadError('The file name is unsafe. Rename the original document and select it again.');
    }
    if (!is_uploaded_file($file['tmp_name'])) throw new DocumentUploadError('The uploaded file is invalid. Choose the file again.', 400);
    $size = filesize($file['tmp_name']);
    if ($size === false || $size === 0 || $file['size'] === 0) throw new DocumentUploadError('Empty files cannot be uploaded.');
    if ($size !== $file['size']) throw new DocumentUploadError('The uploaded file is incomplete. Choose the file again.', 400);
    if ($size > 20 * 1024 * 1024) throw new DocumentUploadError('Files must be 20 MB or smaller.', 413);
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mimes = [
        'pdf' => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    ];
    if (!in_array($ext,allowed_document_extensions(),true)) throw new DocumentUploadError('Only PDF and DOCX files are allowed.', 415);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
    if (!in_array($mime, $mimes[$ext], true)) throw new DocumentUploadError('The uploaded file content does not match its file extension.', 415);
    if ($ext==='pdf' && !prism_pdf_content_is_valid($file['tmp_name'])) throw new DocumentUploadError('The PDF document content is invalid.',415);
    if (!office_container_is_valid($file['tmp_name'], $ext, true)) throw new DocumentUploadError('The Office document container is invalid or exceeds safe archive limits.', 415);
    return ['ext' => $ext, 'mime' => $mime];
}
