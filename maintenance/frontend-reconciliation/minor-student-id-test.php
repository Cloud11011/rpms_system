<?php
/** Pure Student ID validator regression: no database, config or network required. */
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/account_onboarding.php';
$checked=0;
$ok=function(string $value, ?string $existing=null) use (&$checked): void {
    if (onboarding_student_id($value,$existing)!==$value) throw new RuntimeException('Valid ID was modified: '.$value);
    $checked++;
};
$refused=function(string $value, ?string $existing=null) use (&$checked): void {
    try { onboarding_student_id($value,$existing); }
    catch (AccountLifecycleValidation $e) { $checked++; return; }
    throw new RuntimeException('Invalid Student ID was accepted: '.json_encode($value));
};
foreach (['2023-34216','0000-00000','9999-99999'] as $id) $ok($id);
foreach (['202334216','2023-3421','2023-342167','20233-34216','2023 34216','2023_34216','2023--34216','2023-3421a','','２０２３-３４２１６'] as $id) $refused($id);
$ok('202334216','202334216'); // Preserve an unchanged existing numeric ID.
$ok('0000123456','0000123456'); // Keep legacy leading zeros.
$ok('2023-34216','202334216'); // Correct an existing ID to the new format.
$refused('202334217','202334216'); // A changed numeric-only ID must be rejected.
$refused('2023-34216 ','202334216'); // Do not silently normalize at the validator layer.
fwrite(STDOUT,"PASS $checked Student ID validation assertions.\n");
