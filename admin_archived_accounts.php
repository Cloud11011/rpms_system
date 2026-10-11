<?php
// Archived population uses the same authenticated record workspace and lifecycle services.
$archiveWorkspace = true;
$managementType = ($_GET['type'] ?? '') === 'adviser' ? 'adviser' : 'student';
require __DIR__ . '/admin_people.php';
