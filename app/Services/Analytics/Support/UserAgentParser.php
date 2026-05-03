<?php

namespace App\Services\Analytics\Support;

class UserAgentParser
{
    public function extractBrowser(?string $ua): string
    {
        if (!$ua) return 'Outros';

        if (preg_match('/Chrome\/[\d.]+/', $ua) && !preg_match('/Edg\/[\d.]+/', $ua) && !preg_match('/OPR\/[\d.]+/', $ua)) {
            return 'Chrome';
        }
        if (preg_match('/Firefox\/[\d.]+/', $ua)) return 'Firefox';
        if (preg_match('/Safari\/[\d.]+/', $ua) && !preg_match('/Chrome\//', $ua)) return 'Safari';
        if (preg_match('/Edg\/[\d.]+/', $ua)) return 'Edge';
        if (preg_match('/Opera\/[\d.]+/', $ua) || preg_match('/OPR\/[\d.]+/', $ua)) return 'Opera';

        return 'Outros';
    }

    public function extractOS(?string $ua): string
    {
        if (!$ua) return 'Outros';

        if (preg_match('/Windows NT [\d.]+/', $ua)) return 'Windows';
        if (preg_match('/Mac OS X [\d._]+/', $ua) || preg_match('/Macintosh/', $ua)) return 'macOS';
        if (preg_match('/Android [\d.]+/', $ua)) return 'Android';
        if (preg_match('/iPhone OS [\d._]+/', $ua) || preg_match('/iOS [\d._]+/', $ua)) return 'iOS';
        if (preg_match('/Linux/', $ua)) return 'Linux';

        return 'Outros';
    }

    public function extractPrimaryLanguage(?string $acceptLanguage): ?string
    {
        if (!$acceptLanguage) return null;

        $lang = trim(explode(';', explode(',', $acceptLanguage)[0])[0]);

        $map = [
            'pt-BR' => 'Português (Brasil)', 'pt' => 'Português',
            'en'    => 'English',            'en-US' => 'English (US)',
            'es'    => 'Español',            'fr' => 'Français',
            'de'    => 'Deutsch',            'it' => 'Italiano',
            'zh'    => '中文',               'ja' => '日本語',
            'ko'    => '한국어',             'ar' => 'العربية',
            'ru'    => 'Русский',
        ];

        return $map[$lang] ?? $lang;
    }
}
