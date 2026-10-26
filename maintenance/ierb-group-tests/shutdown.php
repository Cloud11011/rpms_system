<?php
require __DIR__.'/bootstrap.php';
// bootstrap verifies the synthetic datadir and database before this administrative command.
db()->exec('SHUTDOWN');
echo "Disposable group test MariaDB stopped.\n";
