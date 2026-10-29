<?php
if (PHP_SAPI !== 'cli') exit; // Test fixture: never reachable over HTTP (B7-F21).
/** Batch 6 unit checks. Bootstrap refuses every database outside the verified disposable datadir. */
require dirname(__DIR__).'/ierb-group-tests/bootstrap.php';
require_once $root.'/includes/account_onboarding.php';
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
try {
    $result=match ($in['action']) {
        'student_id'=>(function()use($in){
            try { onboarding_student_id((string)$in['value'],$in['existing']??null); return ['valid'=>true]; }
            catch (AccountLifecycleValidation $e) { return ['valid'=>false,'message'=>$e->getMessage()]; }
        })(),
        'onboarding_numeric'=>(function()use($pdo){
            // Validation must fail before any transaction, lock or write is attempted.
            try { onboarding_finish($pdo,['id'=>4,'role'=>'student','email'=>'student4@example.test'],['action'=>'complete','name'=>'Synthetic','studentId'=>'2026ABC123']); }
            catch (AccountLifecycleValidation $e) { return ['rejected'=>!$pdo->inTransaction() && str_contains($e->getMessage(),'numbers only')]; }
            return ['rejected'=>false];
        })(),
        default=>throw new RuntimeException('Unknown Batch 6 fixture action.'),
    };
    echo json_encode($result,JSON_THROW_ON_ERROR);
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['error'=>$e->getMessage()]); exit(1); }
