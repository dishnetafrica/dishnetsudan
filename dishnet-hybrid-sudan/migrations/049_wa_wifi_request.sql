-- ══════════════════════════════════════════════════════════════════════════════
-- Migration 049: WhatsApp WiFi Password Request + Smart Dedup
-- DishNet Hybrid v4.11.6
--
-- last_in_body:      smart dedup — skip only if exact same message resent (Fix 3)
-- collected_wifi_pw: captured new WiFi password during starlink_pw_collecting flow
--
-- New state: starlink_pw_collecting
-- Added to state enum comment below for reference:
--   new|support_menu_sent|accounts_menu_sent|support_collecting_name|
--   support_collecting_issue|ticket_created|needs_human|human_active|closed|
--   claude_active|starlink_pw_collecting
-- ══════════════════════════════════════════════════════════════════════════════

ALTER TABLE wa_conversations ADD COLUMN last_in_body    TEXT;
ALTER TABLE wa_conversations ADD COLUMN collected_wifi_pw TEXT;
