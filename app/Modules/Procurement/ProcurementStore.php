<?php

namespace App\Modules\Procurement;

use App\Core\Session;

class ProcurementStore
{
    private const SESSION_KEY = 'procurement_requests';

    public function all(): array
    {
        $requests = Session::get(self::SESSION_KEY);

        if (!is_array($requests)) {
            $requests = $this->seed();
            $this->save($requests);
        }

        usort($requests, fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return $requests;
    }

    public function create(array $user, array $input): array
    {
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

    private function seed(): array
    {
        return [];
    }

    private function save(array $requests): void
    {
        Session::put(self::SESSION_KEY, array_values($requests));
    }
}
