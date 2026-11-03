-- PRISM notification queue review: READ ONLY.
-- Run in Hostinger phpMyAdmin (SQL tab) on the production database BEFORE the notification worker is enabled,
-- and again immediately before choosing PRISM_NOTIFICATION_WORKER_RELEASE_AFTER_ID.
--
-- * Every statement is a SELECT (or a session-only SET) inside a READ ONLY transaction: an accidental write fails.
-- * Nothing is delivered, retried, cancelled, archived or deleted.
-- * No subjects, message bodies, delivery messages, names or email addresses are returned: ids, counts,
--   statuses, categories and timestamps only.
-- * "Eligible" below is the worker's exact selection rule. Stale claims are never resent: the worker marks
--   them Failed/uncertain for operator reconciliation.
-- Keep the result tables with the activation approval record.

SET time_zone = '+08:00';  -- PRISM stores Asia/Manila local times
START TRANSACTION READ ONLY;

-- 0. Context: schema version, review time and the current highest ids (the default release fence).
SELECT 'context' AS section,
       (SELECT v FROM schema_meta WHERE k = 'schema_version') AS schema_version,
       NOW() AS reviewed_at_manila,
       (SELECT MAX(id) FROM notifications) AS max_notification_id,
       (SELECT COUNT(*) FROM notifications) AS notification_rows,
       (SELECT MAX(id) FROM notification_campaigns) AS max_campaign_id,
       (SELECT COUNT(*) FROM notification_campaigns) AS campaign_rows;

-- 1. Counts by delivery status. Live, archived and deleted copies are counted separately.
SELECT 'status' AS section, status,
       SUM(deleted_at IS NULL AND archived_at IS NULL) AS live,
       SUM(deleted_at IS NULL AND archived_at IS NOT NULL) AS archived,
       SUM(deleted_at IS NOT NULL) AS deleted,
       MIN(id) AS min_id, MAX(id) AS max_id
FROM notifications
GROUP BY status
ORDER BY status;

-- 2. What an UNFENCED worker would pick up right now, by reason. The last row (eligible_total) is the sum.
WITH eligible AS (
    SELECT n.id, n.scheduled_at,
           CASE WHEN n.status = 'Scheduled' THEN 'due_scheduled'
                WHEN n.status = 'Sending' THEN 'stale_claim_marked_uncertain_not_resent'
                ELSE 'retryable_failed' END AS reason
    FROM notifications n
    LEFT JOIN notification_campaigns c ON c.id = n.campaign_id
    WHERE n.deleted_at IS NULL AND n.archived_at IS NULL
      AND (c.id IS NULL OR (c.deleted_at IS NULL AND c.archived_at IS NULL AND c.kind <> 'support'
                            AND c.status IN ('Scheduled', 'Sending', 'Failed')))
      AND ((n.status = 'Scheduled' AND n.scheduled_at <= NOW())
        OR (n.status = 'Sending' AND (n.sending_started_at IS NULL OR n.sending_started_at < NOW() - INTERVAL 15 MINUTE))
        OR (n.status = 'Failed' AND n.scheduled_at <= NOW() AND JSON_VALID(n.delivery_info)
            AND JSON_EXTRACT(IF(JSON_VALID(n.delivery_info), n.delivery_info, '{}'), '$.retryable') = true))
)
SELECT 'worker_eligible' AS section, COALESCE(reason, 'eligible_total') AS reason, COUNT(*) AS notifications,
       MIN(id) AS min_id, MAX(id) AS max_id, MIN(scheduled_at) AS oldest_scheduled_at, MAX(scheduled_at) AS newest_scheduled_at
FROM eligible
GROUP BY reason WITH ROLLUP;

-- 3. Scheduled notifications: future versus overdue, by how long they have been overdue.
SELECT 'scheduled' AS section,
       CASE WHEN scheduled_at IS NULL THEN 'no_schedule_time'
            WHEN scheduled_at > NOW() THEN 'future'
            WHEN scheduled_at > NOW() - INTERVAL 1 DAY THEN 'overdue_under_1_day'
            WHEN scheduled_at > NOW() - INTERVAL 7 DAY THEN 'overdue_1_to_7_days'
            WHEN scheduled_at > NOW() - INTERVAL 30 DAY THEN 'overdue_7_to_30_days'
            ELSE 'overdue_over_30_days' END AS bucket,
       COUNT(*) AS notifications, MIN(id) AS min_id, MAX(id) AS max_id,
       MIN(scheduled_at) AS oldest_scheduled_at, MAX(scheduled_at) AS newest_scheduled_at
FROM notifications
WHERE status = 'Scheduled' AND deleted_at IS NULL AND archived_at IS NULL
GROUP BY bucket
ORDER BY bucket;

-- 4. Failed notifications: retryable, uncertain outcome, unbound recipient or final; by recorded attempts.
SELECT 'failed' AS section,
       CASE WHEN delivery_info IS NULL OR NOT JSON_VALID(delivery_info) THEN 'final_no_metadata'
            WHEN JSON_EXTRACT(IF(JSON_VALID(delivery_info), delivery_info, '{}'), '$.uncertain') = true THEN 'uncertain_outcome_needs_reconciliation'
            WHEN JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(delivery_info), delivery_info, '{}'), '$.message')) LIKE 'Recipient identity is unbound%' THEN 'unbound_recipient_needs_review'
            WHEN JSON_EXTRACT(IF(JSON_VALID(delivery_info), delivery_info, '{}'), '$.retryable') = true THEN 'retryable'
            ELSE 'final_retry_limit_or_not_retryable' END AS kind,
       JSON_EXTRACT(IF(JSON_VALID(delivery_info), delivery_info, '{}'), '$.attempts') AS attempts,
       COUNT(*) AS notifications, SUM(scheduled_at <= NOW()) AS retry_time_reached,
       MIN(id) AS min_id, MAX(id) AS max_id
FROM notifications
WHERE status = 'Failed' AND deleted_at IS NULL AND archived_at IS NULL
GROUP BY kind, attempts
ORDER BY kind, attempts;

-- 5. Delivery claims still marked Sending, by age. Stale claims may already have been accepted by the provider.
SELECT 'sending_claims' AS section,
       CASE WHEN sending_started_at IS NULL THEN 'claim_without_start_time'
            WHEN sending_started_at < NOW() - INTERVAL 15 MINUTE THEN 'stale_over_15_minutes'
            ELSE 'in_progress' END AS bucket,
       COUNT(*) AS notifications, MIN(id) AS min_id, MAX(id) AS max_id,
       MIN(sending_started_at) AS oldest_claim, MAX(sending_started_at) AS newest_claim
FROM notifications
WHERE status = 'Sending' AND deleted_at IS NULL
GROUP BY bucket
ORDER BY bucket;

-- 6. Categories of the eligible set: notification type, campaign kind and campaign status.
WITH eligible AS (
    SELECT n.id, n.type, c.kind, c.status AS campaign_status
    FROM notifications n
    LEFT JOIN notification_campaigns c ON c.id = n.campaign_id
    WHERE n.deleted_at IS NULL AND n.archived_at IS NULL
      AND (c.id IS NULL OR (c.deleted_at IS NULL AND c.archived_at IS NULL AND c.kind <> 'support'
                            AND c.status IN ('Scheduled', 'Sending', 'Failed')))
      AND ((n.status = 'Scheduled' AND n.scheduled_at <= NOW())
        OR (n.status = 'Sending' AND (n.sending_started_at IS NULL OR n.sending_started_at < NOW() - INTERVAL 15 MINUTE))
        OR (n.status = 'Failed' AND n.scheduled_at <= NOW() AND JSON_VALID(n.delivery_info)
            AND JSON_EXTRACT(IF(JSON_VALID(n.delivery_info), n.delivery_info, '{}'), '$.retryable') = true))
)
SELECT 'eligible_categories' AS section, type AS notification_type, COALESCE(kind, 'no_campaign') AS campaign_kind,
       COALESCE(campaign_status, '-') AS campaign_status, COUNT(*) AS notifications, MIN(id) AS min_id, MAX(id) AS max_id
FROM eligible
GROUP BY type, kind, campaign_status
ORDER BY notifications DESC;

-- 7. Recipients the eligible set would e-mail, by recipient category and current account state.
--    Stale claims are excluded (never resent). Only 'active' rows would actually be e-mailed;
--    the worker cancels inactive/changed accounts and holds unbound rows for review.
WITH eligible AS (
    SELECT n.id, n.recipient_type, n.recipient_user_id
    FROM notifications n
    LEFT JOIN notification_campaigns c ON c.id = n.campaign_id
    WHERE n.deleted_at IS NULL AND n.archived_at IS NULL
      AND (c.id IS NULL OR (c.deleted_at IS NULL AND c.archived_at IS NULL AND c.kind <> 'support'
                            AND c.status IN ('Scheduled', 'Sending', 'Failed')))
      AND ((n.status = 'Scheduled' AND n.scheduled_at <= NOW())
        OR (n.status = 'Failed' AND n.scheduled_at <= NOW() AND JSON_VALID(n.delivery_info)
            AND JSON_EXTRACT(IF(JSON_VALID(n.delivery_info), n.delivery_info, '{}'), '$.retryable') = true))
)
SELECT 'eligible_recipients' AS section, e.recipient_type,
       CASE WHEN e.recipient_user_id IS NULL THEN 'unbound_held_for_review'
            WHEN u.id IS NULL THEN 'account_missing_cancelled'
            WHEN LOWER(u.status) <> 'active' OR u.role <> e.recipient_type THEN 'inactive_or_changed_cancelled'
            ELSE 'active_would_be_emailed' END AS recipient_state,
       COUNT(*) AS notifications, COUNT(DISTINCT e.recipient_user_id) AS distinct_accounts
FROM eligible e
LEFT JOIN users u ON u.id = e.recipient_user_id
GROUP BY e.recipient_type, recipient_state
ORDER BY e.recipient_type, recipient_state;

-- 8. Campaigns: overall status by kind, then the eligible set per campaign (ids and counts only, no subjects).
SELECT 'campaign_status' AS section, kind, status,
       SUM(deleted_at IS NULL AND archived_at IS NULL) AS live, SUM(archived_at IS NOT NULL OR deleted_at IS NOT NULL) AS archived_or_deleted,
       MIN(id) AS min_id, MAX(id) AS max_id
FROM notification_campaigns
GROUP BY kind, status
ORDER BY kind, status;

WITH eligible AS (
    SELECT n.id, n.campaign_id
    FROM notifications n
    JOIN notification_campaigns c ON c.id = n.campaign_id
    WHERE n.deleted_at IS NULL AND n.archived_at IS NULL
      AND c.deleted_at IS NULL AND c.archived_at IS NULL AND c.kind <> 'support' AND c.status IN ('Scheduled', 'Sending', 'Failed')
      AND ((n.status = 'Scheduled' AND n.scheduled_at <= NOW())
        OR (n.status = 'Sending' AND (n.sending_started_at IS NULL OR n.sending_started_at < NOW() - INTERVAL 15 MINUTE))
        OR (n.status = 'Failed' AND n.scheduled_at <= NOW() AND JSON_VALID(n.delivery_info)
            AND JSON_EXTRACT(IF(JSON_VALID(n.delivery_info), n.delivery_info, '{}'), '$.retryable') = true))
)
SELECT 'eligible_campaigns' AS section, c.id AS campaign_id, c.kind, c.status, c.created_at, c.scheduled_at,
       c.sender_user_id, COUNT(*) AS eligible_notifications, MIN(e.id) AS min_id, MAX(e.id) AS max_id
FROM eligible e
JOIN notification_campaigns c ON c.id = e.campaign_id
GROUP BY c.id, c.kind, c.status, c.created_at, c.scheduled_at, c.sender_user_id
ORDER BY eligible_notifications DESC, c.id
LIMIT 200;

-- 9. Fence preview. By default the fence is the current highest notification id, which holds the entire
--    existing queue. To preview a different reviewed fence, replace the SET value with that number.
SET @prism_fence := (SELECT COALESCE(MAX(id), 0) FROM notifications);
WITH eligible AS (
    SELECT n.id
    FROM notifications n
    LEFT JOIN notification_campaigns c ON c.id = n.campaign_id
    WHERE n.deleted_at IS NULL AND n.archived_at IS NULL
      AND (c.id IS NULL OR (c.deleted_at IS NULL AND c.archived_at IS NULL AND c.kind <> 'support'
                            AND c.status IN ('Scheduled', 'Sending', 'Failed')))
      AND ((n.status = 'Scheduled' AND n.scheduled_at <= NOW())
        OR (n.status = 'Sending' AND (n.sending_started_at IS NULL OR n.sending_started_at < NOW() - INTERVAL 15 MINUTE))
        OR (n.status = 'Failed' AND n.scheduled_at <= NOW() AND JSON_VALID(n.delivery_info)
            AND JSON_EXTRACT(IF(JSON_VALID(n.delivery_info), n.delivery_info, '{}'), '$.retryable') = true))
)
SELECT 'fence_preview' AS section, @prism_fence AS release_after_id,
       COALESCE(SUM(id <= @prism_fence), 0) AS held_backlog_never_touched,
       COALESCE(SUM(id > @prism_fence), 0) AS processed_by_first_enabled_run
FROM eligible;

ROLLBACK;
