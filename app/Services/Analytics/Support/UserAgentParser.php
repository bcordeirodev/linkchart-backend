<?php

namespace App\Services\Analytics\Support;

/**
 * Lightweight User-Agent and Accept-Language parser for analytics display.
 *
 * Used by AudienceAnalyticsService and DashboardAnalyticsService when computing
 * language distribution from raw accept_language strings.
 *
 * Note: extractPrimaryLanguage is the canonical home of Accept-Language parsing
 * for analytics display (migrated from UserAgentAnalyticsService in Phase 2, R-11).
 * The runtime click-recording path uses LinkTrackingService::parseAcceptLanguage,
 * which writes primary_language + language_region columns directly to the database.
 */
class UserAgentParser
{
    /**
     * Extracts a human-readable primary language name from an Accept-Language header.
     *
     * Takes the highest-priority language tag (before the first comma and semicolon),
     * then maps it to a localised display name using a static table of common languages.
     * Unknown tags are returned as-is (the raw BCP-47 tag, e.g. "pt-BR").
     *
     * Mapped languages: pt-BR, pt, en, en-US, es, fr, de, it, zh, ja, ko, ar, ru.
     *
     * Note: This method does NOT skip script subtags (e.g. "zh-Hant-TW" is taken as a
     * single tag). For subtag-aware parsing, use LinkTrackingService::parseAcceptLanguage
     * which correctly handles script codes and stores primary_language + language_region.
     *
     * @param  string|null  $acceptLanguage  Raw Accept-Language header value.
     * @return string|null Human-readable language name, or null if header is empty.
     */
    public function extractPrimaryLanguage(?string $acceptLanguage): ?string
    {
        if (! $acceptLanguage) {
            return null;
        }

        $lang = trim(explode(';', explode(',', $acceptLanguage)[0])[0]);

        $map = [
            'pt-BR' => 'Português (Brasil)', 'pt' => 'Português',
            'en' => 'English',            'en-US' => 'English (US)',
            'es' => 'Español',            'fr' => 'Français',
            'de' => 'Deutsch',            'it' => 'Italiano',
            'zh' => '中文',               'ja' => '日本語',
            'ko' => '한국어',             'ar' => 'العربية',
            'ru' => 'Русский',
        ];

        return $map[$lang] ?? $lang;
    }
}
