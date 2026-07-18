<?php

namespace App\Core;

use DateTimeImmutable;
use DateTimeInterface;

class LocalizedDateFormatter
{
    public function __construct(private readonly Translator $translator)
    {
    }

    public function weekdayShort(DateTimeInterface|string $date): string
    {
        $value = $this->date($date);

        return $this->translator->get('date.weekday.' . $value->format('N'));
    }

    public function format(DateTimeInterface|string $date, string $pattern = 'day_month_year'): string
    {
        $value = $this->date($date);
        $month = $this->translator->get('date.month.' . $value->format('m'));

        return $this->translator->get('date.format.' . $pattern, [
            'day' => $value->format('j'),
            'month' => $month,
            'year' => $value->format('Y'),
        ]);
    }

    public function calendarTitle(string $view, DateTimeInterface|string $focus, DateTimeInterface|string $start, DateTimeInterface|string $end): string
    {
        return match ($view) {
            'week' => $this->translator->get('date.format.range', [
                'start' => $this->format($start, 'day_month'),
                'end' => $this->format($end, 'day_month_year'),
            ]),
            'day' => $this->format($focus),
            default => $this->format($focus, 'month_year'),
        };
    }

    private function date(DateTimeInterface|string $date): DateTimeImmutable
    {
        if ($date instanceof DateTimeInterface) {
            return new DateTimeImmutable($date->format('Y-m-d H:i:sP'));
        }

        return new DateTimeImmutable($date);
    }
}
