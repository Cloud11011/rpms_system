<?php
/** One disposable worker process for the concurrency and interruption scenarios. Datadir-bound through bootstrap. */
if (PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
require_once $root.'/includes/notification_worker.php';
$GLOBALS['provider_delay_ms']=(int)(getenv('PRISM_TEST_PROVIDER_DELAY_MS')?:0);
$GLOBALS['provider_crash']=getenv('PRISM_TEST_PROVIDER_CRASH')==='1';
// Start barrier: the parent releases every child at once so their work genuinely overlaps.
$barrier=getenv('PRISM_TEST_BARRIER');
if ($barrier) for ($n=0; !is_file($barrier); $n++) { if ($n>1000) exit(3); usleep(5000); }
$action=$argv[1]??'';
$result=match($action){
    'worker'=>notification_worker_run($pdo,25,50,(int)($argv[2]??0)),
    'dispatch'=>array_map(fn($id)=>notification_dispatch($pdo,(int)$id)['status']??'Failed',explode(',',$argv[2]??'')),
    'enqueue'=>['queued'=>notification_enqueue_deadline_reminders($pdo)],
    default=>throw new InvalidArgumentException('Unknown child action.'),
};
echo json_encode($result,JSON_THROW_ON_ERROR);
