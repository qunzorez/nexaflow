-- Own closed support sessions belong only to the person who opened them.
-- Safe for existing installations: the column stays NULL until the backfill runs.
ALTER TABLE support_sessions
    ADD COLUMN created_by CHAR(32) NULL AFTER workspace_id,
    ADD KEY idx_support_sessions_creator (workspace_id, created_by, status, closed_at);

-- Use the first message from a non-support user as the best available owner
-- for legacy sessions created before support_sessions.created_by existed.
UPDATE support_sessions ss
JOIN support_messages sm ON sm.session_id = ss.id
JOIN users u ON u.id = sm.sender_id
SET ss.created_by = sm.sender_id
WHERE ss.created_by IS NULL
  AND u.role NOT IN ('admin', 'owner')
  AND COALESCE(u.is_support_agent, 0) = 0
  AND NOT EXISTS (
      SELECT 1
      FROM support_messages earlier
      JOIN users earlier_user ON earlier_user.id = earlier.sender_id
      WHERE earlier.session_id = sm.session_id
        AND earlier_user.role NOT IN ('admin', 'owner')
        AND COALESCE(earlier_user.is_support_agent, 0) = 0
        AND (earlier.created_at < sm.created_at OR (earlier.created_at = sm.created_at AND earlier.id < sm.id))
  );
