<?php

namespace App\Core;

class Translator
{
    private array $messages = [];
    private array $fallbackMessages = [];

    public function __construct(
        private readonly string $langPath,
        private readonly string $locale,
        private readonly string $fallbackLocale,
    ) {
        $this->messages = $this->load($locale);
        $this->fallbackMessages = $this->load($fallbackLocale);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function get(string $key, array $replace = []): string
    {
        $value = $this->messages[$key] ?? $this->fallbackMessages[$key] ?? $key;

        foreach ($replace as $name => $replacement) {
            $value = str_replace(':' . $name, (string) $replacement, $value);
        }

        return $value;
    }

    private function load(string $locale): array
    {
        $file = $this->langPath . '/' . $locale . '.php';

        if (!is_file($file)) {
            return [];
        }

        return require $file;
    }
}

