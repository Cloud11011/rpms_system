<?php
if (PHP_SAPI !== 'cli') exit; // Test fixture: never reachable over HTTP (B7-F21).
require __DIR__.'/bootstrap.php';
// bootstrap verifies the synthetic datadir and database before this administrative command.
db()->exec('SHUTDOWN');
echo "Disposable group test MariaDB stopped.\n";
