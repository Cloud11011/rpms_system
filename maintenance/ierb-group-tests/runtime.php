<?php
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) exit; // Disposable fixture server only (B7-F21).
// Only the disposable copied app uses this auto_prepend_file. No live services.
define('PRISM_TEST_RUNTIME',true);
require __DIR__.'/environment.php';
define('DB_HOST','127.0.0.1');
define('DB_PORT',PRISM_TEST_DB_PORT);
define('DB_NAME','prism_group_synthetic_v11');
define('DB_USER','root');
define('DB_PASS','prism_synthetic_only');
define('APP_ENV','development');
define('APP_BASE_URL','http://127.0.0.1:'.PRISM_TEST_APP_PORT);
define('ALLOWED_EMAIL_DOMAINS',['example.test']);
define('OPENROUTER_API_KEY','synthetic-key-only');
define('OPENROUTER_MODEL','liquid/lfm-2.5-2.6b:free');
define('GMAIL_CLIENT_ID','');
define('GMAIL_CLIENT_SECRET','');
define('GMAIL_REFRESH_TOKEN','');
define('GMAIL_SENDER_EMAIL','');
require __DIR__.'/mock-curl.php';
// Batch 7R: V14 HTTP operations are activated only while a test creates this flag (default: not activated).
define('PRISM_IERB_V14_OPERATIONS',is_file(PRISM_TEST_REVIEW_DIR.'/sandbox/v14-operations.flag'));
// Compliance closure: locally issued deletion-verification evidence exists only while the cleanup rehearsal creates it.
if (is_file(PRISM_TEST_REVIEW_DIR.'/sandbox/hard-delete-verification.json')) {
    $prismTestVerification=json_decode(file_get_contents(PRISM_TEST_REVIEW_DIR.'/sandbox/hard-delete-verification.json'),true,512,JSON_THROW_ON_ERROR);
    define('PRISM_HARD_DELETE_SCHEMA_VERIFIED',true);
    define('PRISM_HARD_DELETE_VERIFICATION',$prismTestVerification['evidence']);
    define('PRISM_HARD_DELETE_VERIFICATION_KEY',$prismTestVerification['key']);
    unset($prismTestVerification);
}
