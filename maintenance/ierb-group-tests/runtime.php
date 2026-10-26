<?php
// Only the disposable copied app uses this auto_prepend_file. No live services.
define('DB_HOST','127.0.0.1');
define('DB_PORT',13527);
define('DB_NAME','prism_group_synthetic_v11');
define('DB_USER','root');
define('DB_PASS','prism_synthetic_only');
define('APP_ENV','development');
define('APP_BASE_URL','http://127.0.0.1:14527');
define('ALLOWED_EMAIL_DOMAINS',['example.test']);
define('OPENROUTER_API_KEY','synthetic-key-only');
define('OPENROUTER_MODEL','liquid/lfm-2.5-2.6b:free');
define('GMAIL_CLIENT_ID','');
define('GMAIL_CLIENT_SECRET','');
define('GMAIL_REFRESH_TOKEN','');
define('GMAIL_SENDER_EMAIL','');
require __DIR__.'/mock-curl.php';
