<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;

/**
 * TranslationService — Internationalisation for SDS content.
 *
 * Loads translation files from templates/translations/{lang}.php and
 * provides key-based lookups with placeholder substitution.
 *
 * Supported languages: en, es, fr (configurable).
 */
class TranslationService
{
    /** @var array<string, array<string, string>> Loaded translations keyed by language */
    private static array $cache = [];

    /** @var string Current language code */
    private string $language;

    /** @var string Fallback language */
    private string $fallback = 'en';

    public function __construct(string $language = 'en')
    {
        $supported = App::config('sds.supported_languages', ['en', 'es', 'fr']);
        $this->language = in_array($language, $supported, true) ? $language : $this->fallback;
    }

    /**
     * Get a translated string by key.
     *
     * @param  string $key          Dot-notation key, e.g. 'section1.title'
     * @param  array  $replacements Placeholder => value pairs
     * @return string               Translated text or the key itself if not found
     */
    public function get(string $key, array $replacements = []): string
    {
        $translations = $this->load($this->language);
        $text = $this->resolve($key, $translations);

        // Fall back to default language if not found
        if ($text === null && $this->language !== $this->fallback) {
            $fallbackTranslations = $this->load($this->fallback);
            $text = $this->resolve($key, $fallbackTranslations);
        }

        if ($text === null) {
            return $key;
        }

        // Apply replacements
        foreach ($replacements as $placeholder => $value) {
            $text = str_replace(':' . $placeholder, (string) $value, $text);
        }

        return $text;
    }

    /**
     * Get all translations for the current language (for bulk use like PDF generation).
     */
    public function all(): array
    {
        return $this->load($this->language);
    }

    /**
     * Get the current language code.
     */
    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * Load a translation file.
     */
    private function load(string $language): array
    {
        if (isset(self::$cache[$language])) {
            return self::$cache[$language];
        }

        $path = App::config('paths.translations', App::basePath() . '/templates/translations');
        $file = $path . '/' . $language . '.php';

        if (!file_exists($file)) {
            self::$cache[$language] = [];
            return [];
        }

        $data = require $file;
        self::$cache[$language] = is_array($data) ? $data : [];

        return self::$cache[$language];
    }

    /**
     * Resolve a dot-notation key in a translations array.
     */
    private function resolve(string $key, array $translations): ?string
    {
        $segments = explode('.', $key);
        $value    = $translations;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return is_string($value) ? $value : null;
    }
}
