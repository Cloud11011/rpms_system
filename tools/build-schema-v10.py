"""Generate the reviewed B6 contract, migration SQL and seed from one specification.
No database connection, configuration loading or installed-data access.
"""
from pathlib import Path
import json, re, hashlib

ROOT = Path(__file__).resolve().parent.parent
KIND = "ENUM('staff','support','workflow','system')"
POLICY = "ENUM('privacy','terms')"
CATEGORY = "ENUM('Ask RPMS Staff','Report a Problem','Account/Access','Document/Submission','IERB Progress','Technical Issue','Other')"
TABLES = {
    'notification_campaigns': {
        'purpose': 'B7 logical send and independent sender history; B8 one-way support and reply correlation.',
        'columns': {
            'id': ('INT NOT NULL AUTO_INCREMENT', 'Stable logical message identifier.'),
            'sender_user_id': ('INT NULL', 'Authenticated sender; SET NULL on account purge.'),
            'kind': (KIND+" NOT NULL DEFAULT 'system'", 'B7/B8 high-level category; existing recipient type preserves subtype.'),
            'support_category': (CATEGORY+' NULL', 'B8 required request category; no ticket engine.'),
            'reply_to_campaign_id': ('INT NULL', 'B8 ordinary composer reply correlation, not a threaded ticket.'),
            'subject': ('VARCHAR(255) NULL', 'Sender copy subject, erased when sender deletes their copy.'),
            'message': ('TEXT NULL', 'Sender copy body; recipient rows independently retain their copies.'),
            'status': ("VARCHAR(20) NOT NULL DEFAULT 'Scheduled'", 'B7 Scheduled/Sending/Sent/Failed/Cancelled; code must cancel before deleting.'),
            'scheduled_at': ('DATETIME NULL', 'B7 schedule.'),
            'sent_at': ('DATETIME NULL', 'B7 logical send completion.'),
            'archived_at': ('DATETIME NULL', 'B7 independent sender archive.'),
            'deleted_at': ('DATETIME NULL', 'B7 independent sender deletion tombstone; erase subject/message.'),
            'created_at': ('DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'Send/request creation time.'),
        },
        'indexes': [('PRIMARY', ['id'], True), ('campaign_sender', ['sender_user_id','created_at'], False), ('campaign_reply', ['reply_to_campaign_id'], False)],
        'fks': [('campaign_sender_fk',['sender_user_id'],'users',['id'],'SET NULL'), ('campaign_reply_fk',['reply_to_campaign_id'],'notification_campaigns',['id'],'SET NULL')],
    },
    'legal_policies': {
        'purpose': 'B6 exactly two stable policies and explicit authoritative current pointers.',
        'columns': {'id': (POLICY+' NOT NULL', 'Only privacy/terms; no arbitrary client types.'), 'current_version_id': ('INT NULL', 'Publication atomically moves pointer; null only during transactional bootstrap.')},
        'indexes': [('PRIMARY',['id'],True), ('policy_current',['id','current_version_id'],False)],
        'fks': [('policy_current_fk',['id','current_version_id'],'legal_policy_versions',['policy_id','id'],'RESTRICT')],
    },
    'legal_policy_versions': {
        'purpose': 'B6 immutable published/pending/rejected text, sequential numbers and one open candidate.',
        'columns': {
            'id': ('INT NOT NULL AUTO_INCREMENT', 'Exact version identity referenced by acceptance.'),
            'policy_id': (POLICY+' NOT NULL', 'Stable policy identity.'),
            'version_number': ('INT NOT NULL', 'Server-assigned monotonic number per policy, including rejected versions.'),
            'title': ('VARCHAR(190) NOT NULL', 'Public heading.'),
            'content': ('MEDIUMTEXT NOT NULL', 'Structured plain source; every render escapes markup.'),
            'change_summary': ("VARCHAR(500) NOT NULL DEFAULT ''", 'Required before submission.'),
            'state': ("ENUM('draft','pending','rejected','published') NOT NULL DEFAULT 'draft'", 'Rejected text remains immutable; start new candidate.'),
            'open_candidate': ("TINYINT GENERATED ALWAYS AS (CASE WHEN state IN ('draft','pending') THEN 1 ELSE NULL END) STORED", 'Unique nullable marker enforces one draft/pending candidate per policy.'),
            'content_sha256': ('CHAR(64) NOT NULL', 'Exact source integrity; verified before rendering/acceptance/publication.'),
            'creator_user_id': ('INT NULL', 'Creator attribution without personal snapshots; SET NULL on purge.'),
            'created_at': ('DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'Candidate creation.'),
            'submitted_at': ('DATETIME NULL', 'Submission freezes candidate.'),
            'published_at': ('DATETIME NULL', 'Publication/effective timestamp.'),
        },
        'indexes': [('PRIMARY',['id'],True), ('policy_version_number',['policy_id','version_number'],True), ('policy_version_identity',['policy_id','id'],True), ('policy_open_candidate',['policy_id','open_candidate'],True), ('policy_creator',['creator_user_id'],False)],
        'fks': [('version_policy_fk',['policy_id'],'legal_policies',['id'],'RESTRICT'), ('version_creator_fk',['creator_user_id'],'users',['id'],'SET NULL')],
    },
    'legal_policy_approvals': {
        'purpose': 'B6 durable immutable publication/rejection evidence, including explicit bootstrap/sole-admin provenance.',
        'columns': {
            'id': ('INT NOT NULL AUTO_INCREMENT', 'Decision identity.'),
            'version_id': ('INT NOT NULL', 'One final decision per immutable candidate.'),
            'reviewer_user_id': ('INT NULL', 'Current reviewer, SET NULL on authorized account purge.'),
            'decision': ("ENUM('approved','rejected') NOT NULL", 'Explicit review result.'),
            'approval_mode': ("ENUM('independent','sole_admin','bootstrap') NOT NULL", 'Distinguish sole-admin exception and approved Batch 5B bootstrap.'),
            'reason': ('VARCHAR(500) NULL', 'Mandatory rejection explanation; no passwords or personal snapshots.'),
            'reviewed_at': ('DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'Server decision timestamp.'),
        },
        'indexes': [('PRIMARY',['id'],True), ('approval_version',['version_id'],True), ('approval_reviewer',['reviewer_user_id'],False)],
        'fks': [('approval_version_fk',['version_id'],'legal_policy_versions',['id'],'RESTRICT'), ('approval_reviewer_fk',['reviewer_user_id'],'users',['id'],'SET NULL')],
    },
    'user_policy_acceptances': {
        'purpose': 'B6 account-bound Terms agreement / Privacy acknowledgment, including partial re-acknowledgment.',
        'columns': {'user_id': ('INT NOT NULL', 'Authenticated subject only; CASCADE on permanent purge.'), 'policy_version_id': ('INT NOT NULL', 'Exact immutable version, RESTRICT deletion.'), 'accepted_at': ('DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'Server timestamp; no IP/device data.')},
        'indexes': [('PRIMARY',['user_id','policy_version_id'],True), ('acceptance_version',['policy_version_id'],False)],
        'fks': [('acceptance_user_fk',['user_id'],'users',['id'],'CASCADE'), ('acceptance_version_fk',['policy_version_id'],'legal_policy_versions',['id'],'RESTRICT')],
    },
    'staff_registration_code': {
        'purpose': 'B9 singleton current generation and transactional consume; activity_logs stores secret-free events.',
        'columns': {
            'slot': ('TINYINT NOT NULL DEFAULT 1', 'CHECK(slot=1) plus PK allows exactly one current global generation.'),
            'generation_id': ('CHAR(32) NOT NULL', 'Random nonsecret generation identity, used by audit after slot is replaced.'),
            'verifier_hash': ('VARCHAR(255) NULL', 'Secure verifier only, cleared on invalidation/expiry/consume; never plaintext.'),
            'state': ("ENUM('pending','acknowledged','invalidated','expired','consumed') NOT NULL DEFAULT 'pending'", 'Unusable until acknowledgment; conditional locked consume permits one winner.'),
            'creator_user_id': ('INT NULL', 'Creator recorded; SET NULL on purge; pending generation must be invalidated by B9 if creator disappears.'),
            'created_at': ('DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'Generation time.'),
            'acknowledged_at': ('DATETIME NULL', 'Starts validity only after saved-code acknowledgment.'),
            'expires_at': ('DATETIME NULL', 'Exactly acknowledgment + 24 hours, enforced by B9 code.'),
            'consumed_at': ('DATETIME NULL', 'Successful registration timestamp.'),
            'consumed_by_user_id': ('INT NULL', 'Winning registration subject; SET NULL on purge.'),
        },
        'indexes': [('PRIMARY',['slot'],True), ('staff_code_generation',['generation_id'],True), ('staff_code_creator',['creator_user_id'],False), ('staff_code_consumer',['consumed_by_user_id'],False)],
        'fks': [('staff_code_creator_fk',['creator_user_id'],'users',['id'],'SET NULL'), ('staff_code_consumer_fk',['consumed_by_user_id'],'users',['id'],'SET NULL')],
        'checks': ['CONSTRAINT staff_code_singleton CHECK (slot = 1)'],
    },
}
MODIFIED = {
    'notifications': {
        'purpose': 'B7/B8 reuse existing recipient delivery copies with independent read/archive/delete and account binding.',
        'columns': {'campaign_id': ('INT NULL','Logical send; null for preserved legacy copies with no fabricated campaign.'), 'recipient_user_id': ('INT NULL','Future exact authenticated recipient binding; CASCADE on purge. Existing role/profile/email semantics remain.'), 'archived_at': ('DATETIME NULL','Independent recipient archive.'), 'deleted_at': ('DATETIME NULL','Independent recipient deletion; B7 erases subject/message/delivery_info without touching others.')},
        'indexes': [('notification_campaign',['campaign_id'],False), ('notification_user',['recipient_user_id','created_at'],False)],
        'fks': [('notification_campaign_fk',['campaign_id'],'notification_campaigns',['id'],'SET NULL'), ('notification_user_fk',['recipient_user_id'],'users',['id'],'CASCADE')],
    }
}

def quote(value):
    return "'"+str(value).replace("'","''")+"'"

def proposal():
    out=['# PRISM V10 — proposed Batch 6 persistence contract', '', 'Recorded before DDL implementation. Starting branch: prism-v10-b06-schema-legal-foundation. Starting HEAD: 23e898bca91b07ad938e2745da2b1183abc74605.', '',
    'Source audit: v9 has 17 InnoDB tables. notifications already stores one recipient per row with read_at, delivery status, schedule and Sending claim time; there is no durable logical send or independent archive/delete. No support or staff-code subsystem exists. documents has version_no, is_current, supersedes_id, review_status/remarks/reviewer/time, rpms_submitted_at/by and override actor/reason/time. activity_logs has entity/version identity, actor, action, timestamp, reason, before/after; ierb_history preserves stage events. Public legal source is includes/legal/privacy.md and terms.md with an escaped structured renderer. No acceptance evidence exists.', '',
    'Every discovered persistent document upload uses documents_api.php?action=upload -> prism_validate_upload -> prism_upload_one (single and bulk), reached by role_portal.php/Student and documents.php/Admin. Adviser upload is explicitly forbidden. Revisions use the same upload path. No IERB attachment or alternate replacement persistence route was found. The photo chooser is a local preview only.', '',
    'B10/B11 require no new fields: retain original reviewed/submitted document rows, append version-bound RPMS-return and resubmission events to activity_logs (reason TEXT, actor, created_at), and create a new superseding revision. A return event must not clear or rewrite original review/submission evidence. Existing override fields and audit history represent override decisions. B12 evidence remains configuration/file based; no verifier table.', '']
    for table, spec in (TABLES|MODIFIED).items():
        out += ['## '+table, '', ('MODIFIED' if table in MODIFIED else 'NEW')+' — '+spec['purpose'], '', '| Column | Type / nullability / default | Required purpose |','|---|---|---|']
        out += [f'| `{col}` | `{definition}` | {why} |' for col,(definition,why) in spec['columns'].items()]
        out += ['', 'Keys/indexes: '+('; '.join(f"{name} ({', '.join(cols)}) {'UNIQUE' if unique else 'INDEX'}" for name,cols,unique in spec['indexes']) or 'none')+'.', '', 'Foreign keys:']
        out += [f'- {name}: ({", ".join(cols)}) → {parent} ({", ".join(target)}), ON DELETE {delete}, ON UPDATE RESTRICT.' for name,cols,parent,target,delete in spec['fks']]
        out += ['', 'Account lifecycle: archive/restore preserves these rows and acceptance validity depends on current pointers. Retention Hold, six-calendar-month retention and seven-day purge grace stay in existing lifecycle code. No new account FK uses RESTRICT. Purge cascades account acceptance/recipient copies and nulls institutional creator/reviewer/sender/code attribution. Legal history remains; no unnecessary personal snapshots. Unresolved document workflow and recoverable file purge remain governed by existing checks. New B7/B8 sends must use existing recipient locking/purge guards; B9 events contain generation ID/action/actor/time only, never a secret/hash.', '']
    out += ['## Future consumers', '', '| Batch | Persistence | Additional migration |','|---|---|---|','| B7 | notification_campaigns; notifications.campaign_id/recipient_user_id/read_at/archived_at/deleted_at; activity_logs secret/content-free deletion events | None planned |','| B8 | campaign.kind=support, support_category, reply_to_campaign_id; snapshot current Admin users into recipient copies at send | None planned |','| B9 | staff_registration_code singleton; activity_logs generation events; users existing complete Admin identity | None planned |','| B10 | documents versions/review/RPMS submission; activity_logs document return/reason/resubmission; ierb_history | None planned |','| B11 | documents override fields; activity_logs reason/is_override and document version identity | None planned |','| B12 | deployment-bound verification file/config plus schema manifest | No DB schema |','', 'Admin-count publication race: lock every users row in PK order with a full locking scan under SERIALIZABLE (next-key locks include insertion gaps), then lock policies in fixed order. Lifecycle account changes already lock affected users; registration/invitation inserts must wait on the locked range. Revalidate identity, active status, password-change eligibility and exact credential fingerprint inside the transaction. Count all currently active admin logins conservatively; a password-change restriction does not erase an active Admin from the independence rule.', '', 'Schema freeze will be declared only after disposable migration parity, compatibility, lifecycle, governance and gate validation.']
    (ROOT/'PRISM_V10_BATCH6_SCHEMA_PROPOSAL.md').write_text('\n'.join(out)+'\n',encoding='utf-8')

def generate():
    baseline=json.loads((ROOT/'tools/schema-v9-contract.json').read_text(encoding='utf-8'))
    checks=[]
    def expect(sql, message):
        checks.append({'sql':sql,'message':message})
    def norm_type(value):
        return value.lower().replace('int(11)','int').replace('tinyint(4)','tinyint')
    def column_check(table,col,typ,nullable,default,extra=''):
        default=None if default in (None,'NULL') else str(default).strip("'").lower().replace('current_timestamp()','current_timestamp')
        cond=f"TABLE_SCHEMA=DATABASE() AND TABLE_NAME={quote(table)} AND COLUMN_NAME={quote(col)} AND REPLACE(REPLACE(LOWER(COLUMN_TYPE),'int(11)','int'),'tinyint(4)','tinyint')={quote(norm_type(typ))} AND IS_NULLABLE={quote(nullable)}"
        cond += " AND REPLACE(LOWER(COALESCE(NULLIF(TRIM(BOTH CHAR(39) FROM COLUMN_DEFAULT),'NULL'),'<null>')),'current_timestamp()','current_timestamp')="+quote(default if default is not None else '<null>')
        if extra: cond+=' AND LOWER(EXTRA)='+quote(extra.lower())
        expect('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE '+cond, table+'.'+col)
    for t in baseline['tables']:
        expect(f"SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME={quote(t['TABLE_NAME'])} AND ENGINE='InnoDB'",t['TABLE_NAME']+' engine')
    for c in baseline['columns']:
        column_check(c['TABLE_NAME'],c['COLUMN_NAME'],c['COLUMN_TYPE'],c['IS_NULLABLE'],c['COLUMN_DEFAULT'],c['EXTRA'])
    def index_check(table,name,cols,unique):
        expect(f"SELECT COUNT(*) FROM (SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME={quote(table)} AND INDEX_NAME={quote(name)} AND INDEX_TYPE='BTREE' AND SUB_PART IS NULL GROUP BY INDEX_NAME HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)={quote(','.join(cols))} AND MIN(NON_UNIQUE)={0 if unique else 1} AND MAX(NON_UNIQUE)={0 if unique else 1}) AS checked_index",table+'.'+name)
    old_indexes={}
    for i in baseline['indexes']:
        old_indexes.setdefault((i['TABLE_NAME'],i['INDEX_NAME'],i['NON_UNIQUE']),[]).append(i['COLUMN_NAME'])
    for (table,name,nonunique),cols in old_indexes.items():index_check(table,name,cols,not nonunique)
    def fk_check(table,name,cols,parent,targets,delete,update='RESTRICT'):
        sql=f"SELECT COUNT(*) FROM (SELECT k.CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME={quote(table)} AND k.CONSTRAINT_NAME={quote(name)} AND k.REFERENCED_TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME={quote(parent)} AND r.DELETE_RULE={quote(delete)} AND r.UPDATE_RULE={quote(update)} GROUP BY k.CONSTRAINT_NAME HAVING GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION)={quote(','.join(cols))} AND GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION)={quote(','.join(targets))}) AS checked_fk"
        expect(sql,table+'.'+name)
    for fk in baseline['foreign_keys']:fk_check(fk['TABLE_NAME'],fk['CONSTRAINT_NAME'],[fk['COLUMN_NAME']],fk['REFERENCED_TABLE_NAME'],[fk['REFERENCED_COLUMN_NAME']],fk['DELETE_RULE'],fk['UPDATE_RULE'])
    preflight=list(checks)
    ddl=[]
    def add(sql,exists=None):ddl.append({'sql':sql,'exists':exists})
    for table,spec in TABLES.items():
        parts=[f'`{col}` {definition}' for col,(definition,_) in spec['columns'].items()]
        for name,cols,unique in spec['indexes']:
            kind='PRIMARY KEY' if name=='PRIMARY' else ('UNIQUE KEY' if unique else 'KEY')+' `'+name+'`'
            parts.append(kind+' ('+','.join('`'+c+'`' for c in cols)+')')
        for name,cols,parent,targets,delete in spec['fks']:
            if table=='legal_policies':continue # cyclic pointer installed after both tables exist
            parts.append(f'CONSTRAINT `{name}` FOREIGN KEY ('+','.join('`'+c+'`' for c in cols)+f') REFERENCES `{parent}` ('+','.join('`'+c+'`' for c in targets)+f') ON DELETE {delete} ON UPDATE RESTRICT')
        parts+=spec.get('checks',[])
        add(f'CREATE TABLE IF NOT EXISTS `{table}` (\n    '+',\n    '.join(parts)+'\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci')
    for table,spec in MODIFIED.items():
        for col,(definition,_) in spec['columns'].items():
            add(f'ALTER TABLE `{table}` ADD COLUMN `{col}` {definition}', f"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME={quote(table)} AND COLUMN_NAME={quote(col)}")
        for name,cols,unique in spec['indexes']:
            add(f'ALTER TABLE `{table}` ADD '+('UNIQUE ' if unique else '')+f'INDEX `{name}` ('+','.join('`'+c+'`' for c in cols)+')',f"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME={quote(table)} AND INDEX_NAME={quote(name)}")
    for table,spec in (TABLES|MODIFIED).items():
        expect(f"SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME={quote(table)} AND ENGINE='InnoDB'",table+' engine')
        for col,(definition,_) in spec['columns'].items():
            if 'GENERATED' in definition:
                expect(f"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME={quote(table)} AND COLUMN_NAME={quote(col)} AND COLUMN_TYPE IN ('tinyint(4)','tinyint') AND EXTRA='STORED GENERATED' AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(GENERATION_EXPRESSION),' ',''),'`',''),'(',''),')',''),CHAR(39),'')='casewhenstateindraft,pendingthen1elsenullend'",table+'.'+col)
                continue
            typ=re.match(r"ENUM\([^)]*\)|\S+",definition)[0]
            default_match=re.search(r' DEFAULT (.*)',definition)
            default=default_match[1] if default_match else (None if 'NOT NULL' in definition else 'NULL')
            column_check(table,col,typ,'NO' if 'NOT NULL' in definition else 'YES',default,'auto_increment' if 'AUTO_INCREMENT' in definition else '')
        for name,cols,unique in spec['indexes']:index_check(table,name,cols,unique)
        for name,cols,parent,targets,delete in spec['fks']:
            fk_check(table,name,cols,parent,targets,delete)
            if table in MODIFIED or table=='legal_policies':
                add(f'ALTER TABLE `{table}` ADD CONSTRAINT `{name}` FOREIGN KEY ('+','.join('`'+c+'`' for c in cols)+f') REFERENCES `{parent}` ('+','.join('`'+c+'`' for c in targets)+f') ON DELETE {delete} ON UPDATE RESTRICT',f"SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME={quote(table)} AND CONSTRAINT_NAME={quote(name)} AND CONSTRAINT_TYPE='FOREIGN KEY'")
    # Check constraint syntax is supported on MariaDB 10.4+ and MySQL 8.0.16+.
    expect("SELECT COUNT(*) FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='staff_code_singleton' AND REPLACE(REPLACE(REPLACE(REPLACE(LOWER(CHECK_CLAUSE),' ',''),'`',''),'(',''),')','')='slot=1'",'Staff Code singleton CHECK')
    total_columns=len(baseline['columns'])+sum(len(s['columns']) for s in (TABLES|MODIFIED).values())
    total_indexes=len(baseline['indexes'])+sum(len(cols) for s in (TABLES|MODIFIED).values() for _,cols,_ in s['indexes'])
    total_fk=len(baseline['foreign_keys'])+sum(len(cols) for s in (TABLES|MODIFIED).values() for _,cols,_,_,_ in s['fks'])
    expect(f'SELECT COUNT(*)={len(baseline["tables"])+len(TABLES)} FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()','Exact v10 table inventory')
    expect(f'SELECT COUNT(*)={total_columns} FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE()','Exact v10 columns')
    expect(f'SELECT COUNT(*)={total_indexes} FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE()','Exact v10 indexes')
    expect(f'SELECT COUNT(*)={total_fk} FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL','Exact v10 FK components')
    expect('SELECT COUNT(*)=0 FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()','No application triggers')
    expect('SELECT COUNT(*)=0 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND TABLE_SCHEMA<>DATABASE()','No external incoming FKs visible')
    seed=[]
    seed_rows={}
    for policy,title in [('privacy','Privacy Policy'),('terms','Terms of Service')]:
        content=(ROOT/f'includes/legal/{policy}.md').read_text(encoding='utf-8').replace('\r\n','\n')
        digest=hashlib.sha256(content.encode()).hexdigest()
        seed_rows[policy]={'title':title,'content':content,'content_sha256':digest}
        seed.append(f'INSERT INTO legal_policies (id) SELECT {quote(policy)} WHERE NOT EXISTS (SELECT 1 FROM legal_policies WHERE id={quote(policy)})')
        seed.append(f"INSERT INTO legal_policy_versions (policy_id,version_number,title,content,change_summary,state,content_sha256,submitted_at,published_at) SELECT {quote(policy)},1,{quote(title)},CONVERT(0x{content.encode().hex()} USING utf8mb4),'Corrected approved Batch 5B source','published',{quote(digest)},NOW(),NOW() WHERE NOT EXISTS (SELECT 1 FROM legal_policy_versions WHERE policy_id={quote(policy)} AND version_number=1)")
        seed.append(f"INSERT INTO legal_policy_approvals (version_id,decision,approval_mode,reason) SELECT id,'approved','bootstrap','Approved Batch 5B checkpoint; no user acceptance implied' FROM legal_policy_versions v WHERE policy_id={quote(policy)} AND version_number=1 AND NOT EXISTS (SELECT 1 FROM legal_policy_approvals a WHERE a.version_id=v.id)")
        seed.append(f"UPDATE legal_policies p JOIN legal_policy_versions v ON v.policy_id=p.id AND v.version_number=1 SET p.current_version_id=v.id WHERE p.id={quote(policy)} AND p.current_version_id IS NULL AND NOT EXISTS (SELECT 1 FROM legal_policy_versions newer WHERE newer.policy_id=p.id AND newer.version_number>1)")
    invariants=[{'sql':"SELECT COUNT(*)=2 FROM legal_policies p JOIN legal_policy_versions v ON v.id=p.current_version_id AND v.policy_id=p.id JOIN legal_policy_approvals a ON a.version_id=v.id WHERE v.state='published' AND v.published_at IS NOT NULL AND a.decision='approved' AND v.content_sha256=SHA2(v.content,256)",'message':'Both explicit current published policies require durable approval and matching source hash'},
        {'sql':"SELECT COUNT(*)=0 FROM legal_policy_versions v LEFT JOIN legal_policy_approvals a ON a.version_id=v.id WHERE (v.state='published' AND (a.decision IS NULL OR a.decision<>'approved' OR v.published_at IS NULL)) OR v.content_sha256<>SHA2(v.content,256)",'message':'Published history and content integrity'}]
    for policy,row in seed_rows.items():invariants.append({'sql':f"SELECT COUNT(*) FROM legal_policy_versions v JOIN legal_policy_approvals a ON a.version_id=v.id WHERE v.policy_id={quote(policy)} AND v.version_number=1 AND v.title={quote(row['title'])} AND BINARY v.content_sha256={quote(row['content_sha256'])} AND v.state='published' AND a.decision='approved' AND a.approval_mode='bootstrap'",'message':policy+' v1 corrected approved seed'})
    plan={'preflight':preflight,'ddl':ddl,'verify':checks,'seed':seed,'invariants':invariants}
    (ROOT/'includes/schema_v10_plan.json').write_text(json.dumps(plan,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
    (ROOT/'includes/schema_v10_seed.json').write_text(json.dumps(seed_rows,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
    def signals(items):
        return '\n'.join(f"    IF ({c['sql']})<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT={quote('v10 contract: '+c['message'])}; END IF;" for c in items)
    manual="""-- Final guarded v9 -> v10 contract. Generated by tools/build-schema-v10.py.
-- MariaDB 10.4+ / MySQL 8.0.16+. Requires routine/DDL privileges. Back up first.
-- DDL commits implicitly; retry the ENTIRE script after resolving any failure.
-- Never change FOREIGN_KEY_CHECKS. Defaults deliberately refuse to migrate.
DELIMITER $$
SET @PRISM_V10_EXPECT_DB = 'REPLACE_WITH_EXACT_DATABASE_NAME'$$
SET @PRISM_V10_BACKUP_AND_STAGING_VERIFIED = 0$$
DROP PROCEDURE IF EXISTS prism_apply_schema_v10$$
CREATE PROCEDURE prism_apply_schema_v10()
BEGIN
    DECLARE got_lock INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        IF got_lock=1 THEN DO RELEASE_LOCK('prism_migrate'); END IF;
        RESIGNAL;
    END;
    IF DATABASE() IS NULL OR DATABASE()<>@PRISM_V10_EXPECT_DB OR @PRISM_V10_BACKUP_AND_STAGING_VERIFIED<>1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Explicit target database, backup and disposable validation required';
    END IF;
    IF @@SESSION.foreign_key_checks<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Foreign-key enforcement required'; END IF;
    SELECT GET_LOCK('prism_migrate',30) INTO got_lock;
    IF COALESCE(got_lock,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migration lock unavailable'; END IF;
    IF COALESCE((SELECT v FROM schema_meta WHERE k='schema_version'),'') NOT IN ('9','10') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Canonical v9 prerequisite required'; END IF;
"""
    manual+=signals(preflight)+'\n'
    for step in ddl:
        manual+= ('    IF ('+step['exists']+')=0 THEN\n' if step['exists'] else '')+'    '+step['sql']+';\n'+('    END IF;\n' if step['exists'] else '')
    manual+=signals(checks)+'\n    START TRANSACTION;\n'
    manual+='\n'.join('    '+sql+';' for sql in seed)+'\n'+signals(invariants)
    manual+="\n    REPLACE INTO schema_meta(k,v) VALUES ('schema_version','10');\n    COMMIT;\n    DO RELEASE_LOCK('prism_migrate');\nEND$$\nCALL prism_apply_schema_v10()$$\nDROP PROCEDURE prism_apply_schema_v10$$\nDELIMITER ;\n"
    (ROOT/'tools/schema-v10-manual.sql').write_text(manual,encoding='utf-8')
    (ROOT/'tools/schema-v10-spec.json').write_text(json.dumps({'new':TABLES,'modified':MODIFIED},indent=2)+'\n',encoding='utf-8')

if __name__ == '__main__':
    if '--generate' in __import__('sys').argv:generate()
    else:proposal()
