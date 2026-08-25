<?php
declare(strict_types=1);

/**
 * TemplateReplyEngine — Keyword-based auto-reply matcher
 *
 * Architecture designed for easy AI upgrade path:
 *   Phase 1 (now):    Keyword matching against wa_reply_templates table
 *   Phase 2 (later):  Add 'ai' match_type that calls OpenAI/Claude API
 *   Phase 3 (future): Fine-tuned model trained on wa_training_data
 *
 * Usage:
 *   $engine = new TemplateReplyEngine($pdo);
 *   $match  = $engine->findMatch($messageText, 'support');
 *   if ($match) {
 *       $reply = $match['response_body'];
 *       $category = $match['category'];
 *   }
 */
class TemplateReplyEngine
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Find the best matching template for an incoming message.
     * @param string      $text     Customer's message text
     * @param string|null $channel  'support', 'accounts', 'marketing', or null for any
     * @return array|null  The matching template row, or null if no match
     */
    public function findMatch(string $text, ?string $channel = null): ?array
    {
        $text = mb_strtolower(trim($text));
        if (empty($text)) return null;

        // Load all enabled templates, ordered by priority
        $sql = 'SELECT * FROM wa_reply_templates WHERE enabled = 1';
        $params = [];
        if ($channel) {
            $sql .= ' AND (channel = ? OR channel = \'both\')';
            $params[] = $channel;
        }
        $sql .= ' ORDER BY priority ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($templates as $tpl) {
            $matched = false;

            switch ($tpl['match_type']) {
                case 'exact':
                    $matched = ($text === mb_strtolower(trim($tpl['match_pattern'])));
                    break;

                case 'regex':
                    $matched = (bool)@preg_match('/' . $tpl['match_pattern'] . '/iu', $text);
                    break;

                case 'keyword':
                default:
                    $keywords = array_map('trim', explode(',', mb_strtolower($tpl['match_pattern'])));
                    foreach ($keywords as $kw) {
                        if (empty($kw)) continue;
                        // Check if keyword appears as a whole word or phrase in the message
                        if (mb_strpos($text, $kw) !== false) {
                            $matched = true;
                            break;
                        }
                    }
                    break;
            }

            if ($matched) {
                // Update hit counter
                $this->db->prepare(
                    "UPDATE wa_reply_templates SET hit_count = hit_count + 1, last_hit_at = datetime('now'), updated_at = datetime('now') WHERE id = ?"
                )->execute([$tpl['id']]);

                return $tpl;
            }
        }

        return null;
    }

    /**
     * Render template variables in a response body.
     * Supports {{customer_name}}, {{phone}}, {{agent_name}}, etc.
     */
    public function renderResponse(string $body, array $vars = []): string
    {
        foreach ($vars as $key => $value) {
            $body = str_replace('{{' . $key . '}}', (string)$value, $body);
        }
        return $body;
    }

    // ── CRUD for templates ───────────────────────────────────────────────

    public function listTemplates(): array
    {
        return $this->db->query('SELECT * FROM wa_reply_templates ORDER BY priority ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTemplate(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM wa_reply_templates WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function saveTemplate(array $data, ?int $id = null): int
    {
        if ($id) {
            $this->db->prepare(
                "UPDATE wa_reply_templates SET name=?, channel=?, match_type=?, match_pattern=?, 
                 response_body=?, category=?, priority=?, enabled=?, updated_at=datetime('now')
                 WHERE id=?"
            )->execute([
                $data['name'], $data['channel'] ?? 'both', $data['match_type'] ?? 'keyword',
                $data['match_pattern'], $data['response_body'], $data['category'] ?? null,
                (int)($data['priority'] ?? 5), (int)($data['enabled'] ?? 1), $id,
            ]);
            return $id;
        }

        $this->db->prepare(
            "INSERT INTO wa_reply_templates (name, channel, match_type, match_pattern, response_body, category, priority, enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $data['name'], $data['channel'] ?? 'both', $data['match_type'] ?? 'keyword',
            $data['match_pattern'], $data['response_body'], $data['category'] ?? null,
            (int)($data['priority'] ?? 5), (int)($data['enabled'] ?? 1),
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function deleteTemplate(int $id): void
    {
        $this->db->prepare('DELETE FROM wa_reply_templates WHERE id = ?')->execute([$id]);
    }

    /**
     * Get template performance stats.
     */
    public function getTemplateStats(): array
    {
        return $this->db->query(
            'SELECT id, name, channel, match_pattern, hit_count, last_hit_at, enabled 
             FROM wa_reply_templates ORDER BY hit_count DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
