<?php

namespace App\Core;

class AuditLogStore
{
    private const VERSION = 1;
    private const MAX_ENTRIES = 500;

    public function record(array $actor, string $action, string $entityType, string $entityId, array $details = []): void
    {
        $data = $this->data();
        array_unshift($data['entries'], [
            'id' => 'AUD-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8),
            'action' => $this->clean($action, 80),
            'entity_type' => $this->clean($entityType, 80),
            'entity_id' => $this->clean($entityId, 160),
            'actor_email' => $this->clean((string) ($actor['email'] ?? ''), 160),
            'actor_name' => $this->clean((string) ($actor['name'] ?? ''), 160),
            'details' => $this->sanitizeDetails($details),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $data['entries'] = array_slice($data['entries'], 0, self::MAX_ENTRIES);
        $this->saveData($data);
    }

    public function recent(int $limit = 12): array
    {
        return array_slice($this->data()['entries'], 0, max(1, $limit));
    }

    private function data(): array
    {
        $path = $this->dataPath();

        if (!is_file($path)) {
            return ['version' => self::VERSION, 'entries' => []];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION || !is_array($decoded['entries'] ?? null)) {
            return ['version' => self::VERSION, 'entries' => []];
        }

        return $decoded;
    }

    private function saveData(array $data): void
    {
        $path = $this->dataPath();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function dataPath(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/audit-log.json';
    }

    private function sanitizeDetails(array $details): array
    {
        $safe = [];

        foreach ($details as $key => $value) {
            if (is_array($value)) {
                $safe[$this->clean((string) $key, 80)] = $this->sanitizeDetails($value);
                continue;
            }

            $safe[$this->clean((string) $key, 80)] = $this->clean((string) $value, 300);
        }

        return $safe;
    }

    private function clean(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return substr($value, 0, $maxLength);
    }
}
