<?php
/** Explicitly authorized local operator entry point; never exposed through HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('PRISM_RESEARCH_PROJECT_ADMIN') !== '1' || !getenv('PRISM_PROJECT_EXPECT_DB')) { fwrite(STDERR,"Explicit project administration opt-in and database binding required.\n"); exit(1); }
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/ierb_group_advancement.php';
try {
    $pdo = db();
    if (getenv('PRISM_PROJECT_EXPECT_DB') !== $pdo->query('SELECT DATABASE()')->fetchColumn()) throw new RuntimeException('Database binding differs.');
    ierb_v14_gate($pdo);
    if (!research_projects_available($pdo)) throw new RuntimeException('Schema 13 required.');
    $input = json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$input['actorUserId'] ?? 0]); $actor=$q->fetch(PDO::FETCH_ASSOC);
    if (!$actor) throw new RuntimeException('Live Administrator required.');
    if (($argv[1] ?? 'preview') === 'preview') {
        $result=research_project_preview($pdo,$actor,array_map('intval',$input['studentIds'] ?? []));
    } elseif ($argv[1] === 'designate-pi') {
        $result=research_project_designate_pi($pdo,$actor,$input['projectId'],$input['expectedRevision'],$input['studentId'],$input['reason']);
    } elseif ($argv[1] === 'apply') {
        $result=research_project_reconcile($pdo,$actor,$input['title'],$input['holderId'],$input['adviserId'],$input['mapping'],$input['reason'],$input['historicalCompletionReference'] ?? '');
    } elseif ($argv[1] === 'rename') {
        $result=research_project_rename($pdo,$actor,$input['projectId'],$input['expectedRevision'],$input['title'],$input['reason']);
    } elseif ($argv[1] === 'reassign-adviser') {
        $result=research_project_reassign_adviser($pdo,$actor,$input['projectId'],$input['expectedRevision'],$input['adviserId'],$input['reason']);
    } else throw new RuntimeException('Unknown operation.');
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,'Project administration refused: '.($e instanceof AccountLifecycleConflict || $e instanceof AccountLifecycleValidation || $e instanceof AccountLifecycleForbidden ? $e->getMessage() : 'Verify input, live Administrator, explicit gates and canonical schema.').PHP_EOL); exit(1);
}
