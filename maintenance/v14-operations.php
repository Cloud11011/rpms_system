<?php
/** Explicitly authorized operator entry point for V14 enrollment and correction cases; never exposed through HTTP.
 * Input is one JSON object on STDIN. Output is JSON. Example:
 *   PRISM_IERB_V14_FOUNDATION=1 PRISM_PROJECT_EXPECT_DB=<db> php maintenance/v14-operations.php enroll < request.json
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('PRISM_IERB_V14_FOUNDATION') !== '1' || !getenv('PRISM_PROJECT_EXPECT_DB')) { fwrite(STDERR,"Explicit V14 operator opt-in and database binding required.\n"); exit(1); }
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/ierb_group_advancement.php';
require_once dirname(__DIR__).'/includes/ierb_v14_requirements.php';
try {
    $pdo = db();
    if (getenv('PRISM_PROJECT_EXPECT_DB') !== $pdo->query('SELECT DATABASE()')->fetchColumn()) throw new RuntimeException('Database binding differs.');
    $input = json_decode(stream_get_contents(STDIN) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $q = $pdo->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$input['actorUserId'] ?? 0]); $actor = $q->fetch(PDO::FETCH_ASSOC);
    if (!$actor) throw new AccountLifecycleForbidden('A live actor account is required.');
    $result = match ($argv[1] ?? '') {
        'enroll' => ierb_v14_enroll($pdo,$actor,(int)$input['studentId'],(string)$input['stage'],(string)$input['reason'],(string)$input['evidence'],(string)$input['requestKey']),
        'correction-open' => ierb_v14_open_correction($pdo,$actor,(int)$input['studentId'],(int)$input['revision'],(string)$input['reason'],$input['documentIds'],(bool)$input['requiresAmendment'],(string)$input['requestKey']),
        'correction-request' => ierb_v14_request_correction($pdo,$actor,(int)$input['studentId'],(int)$input['revision'],(string)$input['reason'],$input['documentIds'],(bool)($input['suggestAmendment'] ?? false),(string)$input['requestKey']),
        'correction-decide' => ierb_v14_decide_correction_request($pdo,$actor,(int)$input['studentId'],(int)$input['revision'],(int)$input['requestEventId'],(string)$input['decision'],
            (string)$input['reason'],(bool)($input['requiresAmendment'] ?? false),$input['documentIds'] ?? null,(string)$input['requestKey']),
        'correction-resolve' => ierb_v14_resolve_correction($pdo,$actor,(int)$input['studentId'],(int)$input['revision'],(string)$input['reason'],(string)$input['requestKey']),
        'correction-queue' => ierb_v14_correction_queue($pdo,$actor),
        default => throw new AccountLifecycleValidation('Unknown operation. Use enroll, correction-open, correction-request, correction-decide, correction-resolve or correction-queue.'),
    };
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $reviewed = $e instanceof AccountLifecycleConflict || $e instanceof AccountLifecycleValidation || $e instanceof AccountLifecycleForbidden || $e instanceof DocumentWorkflowError;
    fwrite(STDERR, json_encode(['ok'=>false,'errorClass'=>$reviewed ? get_class($e) : 'refused',
        'message'=>$reviewed ? $e->getMessage() : 'Verify input, live actor, explicit gates and canonical schema.']).PHP_EOL);
    exit(1);
}
