<?php
declare(strict_types=1);

namespace PostComment;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Editing a form publishes a new immutable version, without rewriting posts. */
final class RoomForms
{
    public function __construct(private PDO $db) {}

    public function publish(string $roomId, array $definition, string $editorId): int
    {
        FormDefinition::validate($definition);
        $serialized = json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $formId = ChatService::id();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $room = $this->db->prepare('SELECT id FROM rooms WHERE id = ?');
            $room->execute([$roomId]);
            if (!$room->fetch()) throw new RuntimeException('Room not found');
            $number = $this->db->prepare('SELECT COALESCE(MAX(version_no),0)+1 FROM form_versions WHERE room_id = ?');
            $number->execute([$roomId]);
            $version = (int)$number->fetchColumn();
            $stmt = $this->db->prepare('INSERT INTO form_versions(id,room_id,version_no,definition_json,published_at) VALUES(?,?,?,?,?)');
            $stmt->execute([$formId,$roomId,$version,$serialized,$now]);
            $stmt = $this->db->prepare('UPDATE rooms SET active_form_version_id = ? WHERE id = ?');
            $stmt->execute([$formId,$roomId]);
            $stmt = $this->db->prepare('INSERT INTO audit_events(actor_id,action,target_type,target_id,created_at) VALUES(?,?,?,?,?)');
            $stmt->execute([$editorId,'form_published','room',$roomId,$now]);
            $this->db->exec('COMMIT');
            return $version;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    public function versions(string $roomId): array
    {
        $stmt = $this->db->prepare('SELECT id,version_no,published_at FROM form_versions WHERE room_id = ? ORDER BY version_no DESC');
        $stmt->execute([$roomId]);
        return $stmt->fetchAll();
    }

    /**
     * Translate the form editor's rows into the same form schema used by posting.
     * Rows with no key and no label are ignored. Disabled rows are omitted.
     */
    public static function fromEditor(array $input): array
    {
        $title = trim((string)($input['title'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $submit = trim((string)($input['submit_label'] ?? ''));
        if ($title === '' || mb_strlen($title) > 160 || mb_strlen($description) > 5000 || $submit === '' || mb_strlen($submit) > 60) {
            throw new InvalidArgumentException('Title, description or button text is invalid');
        }
        $source = $input['fields'] ?? null;
        if (!is_array($source) || count($source) > 40) throw new InvalidArgumentException('Invalid fields');
        $rows = [];
        foreach ($source as $raw) {
            if (!is_array($raw) || !empty($raw['omit'])) continue;
            $key = trim((string)($raw['key'] ?? ''));
            $label = trim((string)($raw['label'] ?? ''));
            if ($key === '' && $label === '') continue;
            $type = (string)($raw['type'] ?? 'text');
            $row = [
                'key' => $key,
                'type' => $type,
                'label' => $label,
                'required' => !empty($raw['required']),
            ];
            if (!empty($raw['comment_body'])) $row['role'] = 'comment_body';
            if (in_array($type, ['select','radio'], true)) {
                $options = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', (string)($raw['options'] ?? '')) ?: []), static fn($v) => $v !== ''));
                if (count($options) < 1 || count($options) > 30 || count(array_unique($options)) !== count($options)) {
                    throw new InvalidArgumentException('Select/radio options must contain 1–30 unique values');
                }
                $row['options'] = $options;
            }
            $rows[] = $row;
        }
        $definition = [
            'schema_version'=>1,
            'title'=>$title,
            'description'=>$description,
            'submit_label'=>$submit,
            'fields'=>$rows,
        ];
        FormDefinition::validate($definition);
        return $definition;
    }
}
