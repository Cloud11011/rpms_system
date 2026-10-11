<?php
/** Test-only helpers; no installed config, transport or database bootstrap. */
require_once __DIR__.'/../includes/legal_policy.php';
require_once __DIR__.'/../includes/document_catalog.php';
require_once __DIR__.'/../includes/account_lifecycle.php';
function db(): PDO { return $GLOBALS['pdo']; }
function next_stage(string $stage): string { $i=array_search($stage,STAGE_SEQUENCE,true); return STAGE_SEQUENCE[min((int)$i+1,count(STAGE_SEQUENCE)-1)]; }
function current_user(): ?array { return $GLOBALS['actor'] ?? null; }
function log_api_error(...$args): void { $GLOBALS['workflowErrors'][]=$args; }
function send_notification_email(...$args): array {
    if (db()->inTransaction()) throw new RuntimeException('Email before commit.');
    $GLOBALS['delivered'][]=$args; return ['ok'=>true,'channel'=>'log'];
}
require_once __DIR__.'/../workflow.php';
