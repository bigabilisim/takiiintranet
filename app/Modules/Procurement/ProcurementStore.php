<?php

namespace App\Modules\Procurement;

use App\Core\StateStore;

class ProcurementStore
{
    private const STATE_KEY = 'procurement';
    private const VERSION = 1;

    public function __construct(private readonly StateStore $stateStore)
    {
    }

    public function all(): array
    {
        $requests = $this->data()['requests'];

        usort($requests, fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return $requests;
    }

    public function create(array $user, array $input): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::STATE_KEY, $this->dataPath(), $this->emptyData());
        $title = trim((string) ($input['title'] ?? ''));
        $vendor = trim((string) ($input['vendor'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));
        $amount = $this->normalizeAmount((string) ($input['amount'] ?? ''));
        $neededOn = $this->cleanDate((string) ($input['needed_on'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));

        if ($title === '' || $vendor === '' || $category === '' || $amount <= 0 || $neededOn === null || $reason === '') {
            return ['ok' => false, 'message' => 'procurement.flash.invalid'];
        }

        $requests = $this->all();
        $requests[] = [
            'id' => $this->nextId($requests),
            'requester' => $user['name'] ?? 'Unknown',
            'department' => $user['department'] ?? 'General',
            'title' => substr($title, 0, 140),
            'vendor' => substr($vendor, 0, 120),
            'category' => substr($category, 0, 80),
            'amount' => $amount,
            'currency' => 'TRY',
            'needed_on' => $neededOn,
            'reason' => substr($reason, 0, 1200),
            'status' => 'waiting_department',
            'created_at' => date('Y-m-d H:i'),
        ];

        $this->save($requests);

        return ['ok' => true, 'message' => 'procurement.flash.created'];
    }

    public function formatAmount(array $request): string
    {
        return number_format((float) ($request['amount'] ?? 0), 2, ',', '.') . ' ' . ($request['currency'] ?? 'TRY');
    }

    private function normalizeAmount(string $amount): float
    {
        $normalized = str_replace(['.', ','], ['', '.'], $amount);

        return round(max(0, (float) $normalized), 2);
    }

    private function cleanDate(string $date): ?string
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);

        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return null;
        }

        return $date;
    }

    private function nextId(array $requests): string
    {
        return 'PR-2026-' . (1001 + count($requests));
    }

    private function data(): array
    {
        $data = $this->stateStore->read(self::STATE_KEY, $this->dataPath(), $this->emptyData());

        return [
            'version' => self::VERSION,
            'requests' => is_array($data['requests'] ?? null) ? $data['requests'] : [],
        ];
    }

    private function save(array $requests): void
    {
        $this->stateStore->write(self::STATE_KEY, $this->dataPath(), [
            'version' => self::VERSION,
            'requests' => array_values($requests),
        ]);
    }

    private function emptyData(): array
    {
        return ['version' => self::VERSION, 'requests' => []];
    }

    private function dataPath(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/storage/procurement.json';
    }
}
