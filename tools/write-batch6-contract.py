"""Render the verified v10 inventory as the complete human-readable frozen contract."""
from pathlib import Path
import json, hashlib

root = Path(__file__).resolve().parent.parent
manifest = root / 'includes/account_lifecycle_schema.json'
inventory = json.loads(manifest.read_text(encoding='utf-8'))
spec = json.loads((root / 'tools/schema-v10-spec.json').read_text(encoding='utf-8'))
purposes = {
    'account_invitations': 'Existing Student/Adviser invitation ownership, one-use setup verifier, inviter attribution and expiry; B6 never infers legal acceptance from invitation acceptance.',
    'account_purge_jobs': 'Existing durable recovery journal binding for transactional account/file purge; B12 keeps deployment verification outside the database.',
    'activity_logs': 'Existing actor/action/entity/version/time/reason/before/after evidence. B7/B8 deletion events omit content; B9 events omit secrets/verifiers; B10 return/resubmission and B11 override append version-bound events.',
    'advisers': 'Existing Adviser profile, assignment identity, archive/restore/retention/hold state. B10 reviewers remain governed by existing assignment checks.',
    'ai_outputs': 'Existing owned generated research outputs. B8 support bodies must be excluded from provider context and metrics.',
    'calendar_deadlines': 'Existing official deadline author, scope, time and status; existing lifecycle unresolved-workflow guards remain.',
    'calendar_deadline_groups': 'Existing official deadline group visibility.',
    'calendar_deadline_recipients': 'Existing explicit Student deadline recipient snapshot.',
    'documents': 'Existing immutable revision identity, supersession/current selection, Adviser review, formal RPMS submission, Admin override, summary and ownership; B10/B11 reuse these exact fields.',
    'ierb_history': 'Existing institutional stage/progress event history; workflow truth survives notification deletion.',
    'notifications': 'Existing recipient delivery/copy, subtype, status, read state, scheduling and Sending lease. B7 adds logical campaign/account binding and independent archive/delete timestamps; B8 uses recipient snapshots.',
    'password_resets': 'Existing one-use reset/setup verifiers and expiry; legal acceptance is independently required after password setup.',
    'reports': 'Existing owned generated report metadata; B8 support content stays outside research reports/exports.',
    'schema_meta': 'Canonical migration stamp. Final schema_version is 10; never advance until validation and seed invariants pass.',
    'stage_labels': 'Existing configured IERB display labels.',
    'students': 'Existing research/profile/assignment identity, current progress, archive/restore/retention/hold; B10 new revisions retain existing account ownership.',
    'users': 'Existing authentication identity, role, active status, password-security state and account link. B6 acceptance binds to this stable ID; B9 future Staff-Code registration creates an active complete Admin without acceptance.'
}
purposes.update({table: shape['purpose'] for table, shape in spec['new'].items()})

def cell(value):
    return str(value).replace('|', '&#124;').replace('\n', ' ')

lines = ['# PRISM V10 Schema Contract', '',
         'Schema v10 is finalized and frozen by Batch 6. B7–B12 must use this contract without adding tables, columns, indexes or foreign keys. If a later requirement cannot be represented here, stop and obtain a revised design; do not silently create schema v11.', '',
         'Schema v10 is frozen after Batch 6. B7–B12 are code-only against this contract.', '',
         'This is the implementation contract for review. It does not authorize any installed, staging or production migration. The one persistent v9 → v10 staging migration remains subject to the owner’s separate authorization.', '',
         'Canonical inventory: `includes/account_lifecycle_schema.json`; additive specification: `tools/schema-v10-spec.json`; exact SQL checks, generated-column/check constraints and seed invariants: `includes/schema_v10_plan.json`.', '',
         f'Inventory SHA-256: `{hashlib.sha256(manifest.read_bytes()).hexdigest()}`. There are {len(inventory["tables"])} InnoDB tables, {len(inventory["columns"])} columns, {len(inventory["indexes"])} ordered index components and {len(inventory["foreign_keys"])} ordered FK components. No triggers. MariaDB display-width/default spelling is normalized by the verifier; logical definitions remain exact.', '',
         '## Future-batch compatibility', '',
         '| Batch | Persistent requirement | Frozen representation / mandatory behavior |', '|---|---|---|',
         '| B7 | One logical send, independent Sent History and recipient read/archive/delete | `notification_campaigns` plus existing `notifications`. Sender deletion erases campaign subject/message; recipient deletion erases only that row’s subject/message/delivery_info. B7 account-purge integration must erase the sender copy before its user FK becomes NULL, while other account recipient copies survive. Preserve other copies. Retain only identifier/actor/action/time/category audit. Cancel Scheduled before archive/delete; reject unstable Sending. Existing recipient type retains subtype semantics. |',
         '| B8 | Seven support categories, active-Admin recipient snapshot, ordinary composer response | `kind=support`, `support_category`, `reply_to_campaign_id`. Insert recipient rows for active Admins at request time; do not synthesize later recipients. Correlation is optional one-way response context, with no ticket engine/chat/attachment/assignment/SLA. Exclude support bodies using campaign kind from AI/research/export/metrics paths. Pending users access future Support only after security/legal gates. |',
         '| B9 | One global code, unrecoverable secret, acknowledgment clock, one winner | `staff_registration_code.slot=1` checked singleton; generation identifier, verifier only, creator, state, acknowledgment/expiry/consumption fields. Generate a secure secret and show once in the response, never DB/session/URL/log. Pending is unusable. Acknowledge starts 24 hours; lost pending secret requires regenerate. Lock singleton and conditionally consume while unexpired in the same registration transaction. Log generation identifier/action/actor/time only. No acceptance backfill. |',
         '| B10 | Adviser Approve → automatic formal RPMS submission | In one controlled operation set existing `documents.review_status/reviewed_by/reviewed_at` and `rpms_submitted_at/rpms_submitted_by`, retain `version_no`, append document-ID-bound `activity_logs` events. No auto_submitted flag. |',
         '| B10 | RPMS Return → new revision → review → resubmission | Append mandatory `rpms_returned_for_revision` event bound to original `documents.id`, Admin actor, `reason` and `created_at`. Do not clear original review/submission fields. Determine returned eligibility from the latest version-bound workflow event, ordered by `created_at,id`; update workflow/locking and unresolved-retention projections in B10 to treat a returned current version as unfinished. Insert new document with `supersedes_id` and incremented `version_no`, preserving original row; append review/resubmission events for new ID. Existing `is_current` controls current selection. |',
         '| B11 | Override Approve/Revision/Deny and formal submission where eligible | Existing `documents.admin_override`, `override_reason`, `override_by`, `override_at`, review/submission fields and immutable version-bound `activity_logs` including `is_override` and mandatory reason. A comment with no state change stays a comment. Validate final state machine transactionally in B11. |',
         '| B12 | Persistent deployment-bound hard-delete evidence | Configuration/private evidence file, manifest hash, database/server binding, complete schema/FK/check verification, FK enforcement and external-dependency/visibility assumptions. No new database table. Existing 24-hour expiry remains until B12 implements this code-only change. |',
         '| B6 legal governance / acceptance | Versioned Privacy and Terms, immutable publication, independent/sole review, exact account acceptance | `legal_policies`, `legal_policy_versions`, `legal_policy_approvals`, `user_policy_acceptances`; explicit same-policy current pointer, one open candidate, unique review, account/exact-version key. |', '',
         '## Legal and lifecycle invariants', '',
         '- Exactly Privacy and Terms policy keys. Explicit current pointer must resolve a published version of the same policy with durable approved evidence and verified source hash. No MAX(version) or static-file fallback.',
         '- Unique `(policy_id,version_number)` and generated `open_candidate = CASE WHEN state IN (\'draft\',\'pending\') THEN 1 ELSE NULL END`, stored, with unique `(policy_id,open_candidate)`: at most one open candidate per policy. Pending, rejected and published text is immutable through all application paths. Rejected numbers are never reused.',
         '- Approval has unique `version_id`; publication is one transaction with independently eligible reviewer (or password-confirmed explicit sole-Admin exception), audit, published state/time and current pointer. Bootstrap is a migration-only approval mode with no invented human approver.',
         '- Exact account/version acceptance composite primary key. No IP, user-agent, role, proxy, auto-acceptance or session-only evidence. Current version changes invalidate only the changed policy. Archive/restore retains historical acceptance; restore still requires password setup and any newly published version.',
         '- Account archive/restore, six-calendar-month retention, seven-day grace, Retention Hold, unresolved workflow and recoverable storage checks remain in existing lifecycle services. No new user FK uses RESTRICT. File rollback/finalization remains in the existing purge journal.', '',
         '| New table / modified ownership | Archive / restore / hold / retention | Permanent purge / institutional history |', '|---|---|---|',
         '| notification_campaigns | Preserve sender history; no archive operation deletes it. Future sends must use existing account/purge locking. | Sender SET NULL; B7 must erase the purged account’s sender-copy subject/message with a tombstone before deleting the account. Other recipient copies survive. Self reply reference SET NULL if a campaign is actually removed. Do not retain a deleted body in audit. |',
         '| notifications new recipient_user_id | Preserve current delivery during archive; legacy Sending/purge guards still apply. | Account-bound copies CASCADE, campaign deletion SET NULL. Legacy ownership rules remain for old NULL-bound rows. |',
         '| legal_policies | Institutional pointer persists; no account dependency. | Policy/version cross-reference RESTRICT protects institutional history, never references a user. |',
         '| legal_policy_versions | Preserve draft/history through archive and hold. Orphan draft can be adopted by an eligible Admin with audit. | Creator SET NULL; title/text/version/hash/history remain. No copied creator personal snapshot. |',
         '| legal_policy_approvals | Durable review state survives archive/restore/retention. | Reviewer SET NULL; decision/mode/time/reason remain; version RESTRICT protects history. |',
         '| user_policy_acceptances | Preserve exact historical evidence; restored account checks current pointer. | Subject CASCADE, version RESTRICT. No institutional text loss. |',
         '| staff_registration_code | Pending/expiry logic belongs to B9; creator archiving grants no extra privilege. | Creator and consumer SET NULL; generation/state/time remain, verifier is erased on consumption/invalidation. Events in existing audit exclude secrets. |', '',
         '## Complete table inventory', '',
         'Defaults below use normalized canonical values: NULL denotes SQL NULL/no explicit non-null default. `EXTRA` records AUTO_INCREMENT/generated storage. Index rows preserve key order and prefixes. Every FK lists ON DELETE and ON UPDATE explicitly.', '']
for table in inventory['tables']:
    name = table['TABLE_NAME']
    lines += [f'### {name}', '', purposes[name], '',
              f'Engine: {table["ENGINE"]}. '+('NEW in v10.' if name in spec['new'] else 'Modified in v10.' if name == 'notifications' else 'Existing v9 definition retained.'), '',
              '| Column | Type | Nullable | Default | Key marker | Extra |', '|---|---|---|---|---|---|']
    for column in inventory['columns']:
        if column['TABLE_NAME'] != name: continue
        lines.append('| '+' | '.join(cell(column.get(key) if column.get(key) is not None else 'NULL') for key in ['COLUMN_NAME','COLUMN_TYPE','IS_NULLABLE','COLUMN_DEFAULT','COLUMN_KEY','EXTRA'])+' |')
    if name in spec['new']:
        lines += ['', '| v10 column | Required purpose / consumer |', '|---|---|']
        lines += [f'| {column} | {cell(details[1])} |' for column, details in spec['new'][name]['columns'].items()]
    if name == 'notifications':
        lines += ['', 'New columns: campaign_id correlates one logical send; recipient_user_id binds the stable account; archived_at and deleted_at represent independent recipient lifecycle. Old rows retain NULL new values and all legacy data.']
    lines += ['', '| Index | Unique | Ordered columns (prefix if present) | Type |', '|---|---|---|---|']
    indexes = {}
    for index in inventory['indexes']:
        if index['TABLE_NAME']==name: indexes.setdefault(index['INDEX_NAME'], []).append(index)
    for key, entries in indexes.items():
        columns = ', '.join(e['COLUMN_NAME']+(f'({e["SUB_PART"]})' if e['SUB_PART'] else '') for e in sorted(entries,key=lambda e:e['SEQ_IN_INDEX']))
        lines.append(f'| {key} | {"YES" if entries[0]["NON_UNIQUE"]==0 else "NO"} | {columns} | {entries[0]["INDEX_TYPE"]} |')
    fks = {}
    for fk in inventory['foreign_keys']:
        if fk['TABLE_NAME']==name: fks.setdefault(fk['CONSTRAINT_NAME'], []).append(fk)
    if fks:
        lines += ['', '| Foreign key | Columns | References | ON DELETE | ON UPDATE |', '|---|---|---|---|---|']
        for key, entries in fks.items():
            lines.append(f'| {key} | '+', '.join(e['COLUMN_NAME'] for e in entries)+' | '+entries[0]['REFERENCED_TABLE_NAME']+' ('+', '.join(e['REFERENCED_COLUMN_NAME'] for e in entries)+f') | {entries[0]["DELETE_RULE"]} | {entries[0]["UPDATE_RULE"]} |')
    else: lines += ['', 'Foreign keys: none.']
    if name=='staff_registration_code': lines += ['', 'Additional enforced CHECK: `slot = 1`. Primary key guarantees only one singleton row; empty table is allowed before first generation.']
    if name=='legal_policy_versions': lines += ['', 'Additional verified generated expression: `CASE WHEN state IN (\'draft\',\'pending\') THEN 1 ELSE NULL END`, STORED. Generated unique candidate key is required even though the ordinary inventory records only the storage/type/key attributes.']
    lines.append('')
(root/'PRISM_V10_SCHEMA_CONTRACT.md').write_text('\n'.join(lines),encoding='utf-8')
print('Wrote complete v10 contract; no database connection or mutation.')
