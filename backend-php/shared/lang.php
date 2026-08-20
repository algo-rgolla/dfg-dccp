<?php
declare(strict_types=1);

namespace App\Shared;

use App\Shared\SessionHelper;

class Lang
{
    private static string $defaultLang = 'en';
    private static array $cache = [];

    /**
     * Get the currently active language from session (or fallback).
     */
    public static function getActiveLang(): string
    {
        return SessionHelper::get('lang', self::$defaultLang);
    }

    /**
     * Set the active language, if translation file exists.
     */
    public static function setActiveLang(string $lang): void
    {
        if (is_file(__DIR__ . "/../lang/{$lang}.php")) {
            SessionHelper::set('lang', $lang);
        }
    }

    /**
     * Translate a key using the active language, falling back to default.
     */
    public static function t(string $key, array $replacements = []): string
    {
        $lang = self::getActiveLang();

        // Load into cache once per language
        if (!isset(self::$cache[$lang])) {
            $file = __DIR__ . "/../lang/{$lang}.php";
            self::$cache[$lang] = is_file($file) ? (require $file) : [];
        }
        if (!isset(self::$cache[self::$defaultLang])) {
            $file = __DIR__ . "/../lang/" . self::$defaultLang . ".php";
            self::$cache[self::$defaultLang] = is_file($file) ? (require $file) : [];
        }

        $value = self::$cache[$lang][$key]
            ?? self::$cache[self::$defaultLang][$key]
            ?? $key;

        foreach ($replacements as $k => $v) {
            $value = str_replace(':' . $k, (string)$v, $value);
        }
        return $value;
    }

    /**
     * Return available languages (based on lang/*.php files).
     */
    public static function availableLanguages(): array
    {
        $files = glob(__DIR__ . '/../lang/*.php');
        return array_map(fn($f) => basename($f, '.php'), $files);
    }
}

// ---------------------------------------------------------
// Global helper function (__t) for convenience in views
// ---------------------------------------------------------
if (!function_exists('__t')) {
    function __t(string $key, array $replacements = []): string
    {
        return \App\Shared\Lang::t($key, $replacements);
    }
}
