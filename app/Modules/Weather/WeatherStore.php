<?php

namespace App\Modules\Weather;

class WeatherStore
{
    private const CACHE_TTL = 1800;

    public function weekly(): array
    {
        return array_map(
            fn (array $location): array => $this->forecastFor($location),
            $this->locations()
        );
    }

    private function forecastFor(array $location): array
    {
        $cache = $this->loadCache();
        $cacheKey = $location['slug'];

        if (isset($cache[$cacheKey]) && (time() - (int) ($cache[$cacheKey]['fetched_at'] ?? 0)) < self::CACHE_TTL) {
            return $cache[$cacheKey]['data'];
        }

        $forecast = $this->fetchForecast($location) ?? $this->fallbackForecast($location);
        $cache[$cacheKey] = [
            'fetched_at' => time(),
            'data' => $forecast,
        ];
        $this->saveCache($cache);

        return $forecast;
    }

    private function fetchForecast(array $location): ?array
    {
        $query = http_build_query([
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
            'forecast_days' => 7,
            'timezone' => 'Europe/Istanbul',
        ]);

        $context = stream_context_create([
            'http' => [
                'timeout' => 2.5,
            ],
        ]);

        $json = @file_get_contents('https://api.open-meteo.com/v1/forecast?' . $query, false, $context);

        if (!is_string($json)) {
            return null;
        }

        $payload = json_decode($json, true);

        if (!is_array($payload) || !isset($payload['daily']['time'])) {
            return null;
        }

        $days = [];
        $daily = $payload['daily'];

        foreach ($daily['time'] as $index => $date) {
            $days[] = [
                'date' => $date,
                'label' => (new \DateTimeImmutable($date))->format('d M'),
                'code' => (int) ($daily['weather_code'][$index] ?? 0),
                'condition_key' => $this->conditionKey((int) ($daily['weather_code'][$index] ?? 0)),
                'max' => round((float) ($daily['temperature_2m_max'][$index] ?? 0)),
                'min' => round((float) ($daily['temperature_2m_min'][$index] ?? 0)),
                'rain' => (int) ($daily['precipitation_probability_max'][$index] ?? 0),
            ];
        }

        return [
            'name_key' => $location['name_key'],
            'source' => 'Open-Meteo',
            'days' => $days,
        ];
    }

    private function fallbackForecast(array $location): array
    {
        return [
            'name_key' => $location['name_key'],
            'source' => 'Fallback',
            'days' => [],
        ];
    }

    private function conditionKey(int $code): string
    {
        return match (true) {
            $code === 0 => 'weather.condition.clear',
            in_array($code, [1, 2], true) => 'weather.condition.partly_cloudy',
            $code === 3 => 'weather.condition.cloudy',
            in_array($code, [45, 48], true) => 'weather.condition.fog',
            in_array($code, [51, 53, 55, 56, 57], true) => 'weather.condition.drizzle',
            in_array($code, [61, 63, 65, 66, 67, 80, 81, 82], true) => 'weather.condition.rain',
            in_array($code, [71, 73, 75, 77, 85, 86], true) => 'weather.condition.snow',
            in_array($code, [95, 96, 99], true) => 'weather.condition.storm',
            default => 'weather.condition.cloudy',
        };
    }

    private function locations(): array
    {
        return [
            [
                'slug' => 'antalya-gaziler',
                'name_key' => 'weather.location.antalya_gaziler',
                'latitude' => 37.00782,
                'longitude' => 30.78506,
            ],
            [
                'slug' => 'bursa-karacabey',
                'name_key' => 'weather.location.bursa_karacabey',
                'latitude' => 40.21323,
                'longitude' => 28.36120,
            ],
        ];
    }

    private function loadCache(): array
    {
        $path = $this->cachePath();

        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function saveCache(array $cache): void
    {
        $path = $this->cachePath();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function cachePath(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/storage/weather-cache.json';
    }
}

