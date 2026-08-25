-- Migration 018: Add evo_jid column for Evolution API lazy-fetch
-- Maps local conversations to Evolution API remoteJid (e.g. 135455014137882@lid)
ALTER TABLE wa_conversations ADD COLUMN evo_jid TEXT DEFAULT NULL;
CREATE INDEX IF NOT EXISTS idx_wa_conv_evo_jid ON wa_conversations(evo_jid);
