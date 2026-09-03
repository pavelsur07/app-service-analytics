<?php

declare(strict_types=1);

namespace App\Links\Domain;

final class BotDetector
{
    private const array BOT_MARKERS = [
        'googlebot',
        'bingbot',
        'duckduckbot',
        'yandexbot',
        'applebot',
        'twitterbot',
        'slackbot',
        'telegrambot',
        'discordbot',
        'linkedinbot',
        'facebookexternalhit',
        'whatsapp/',
        'petalbot',
        'semrushbot',
        'ahrefsbot',
        'mj12bot',
        'dotbot',
        'uptimerobot',
        'bot/',
        'crawler',
        'spider',
        'slurp',
        'preview',
        'scanner',
        'headless',
        'proofpoint',
        'barracuda',
        'safelinks',
        'mimecast',
        'curl/',
        'wget/',
        'python-requests',
        'go-http-client',
        'okhttp',
        'libwww-perl',
        'java/',
    ];

    public function isBot(?string $userAgent): bool
    {
        if (null === $userAgent || '' === trim($userAgent)) {
            return true;
        }
        if (!mb_check_encoding($userAgent, 'UTF-8')) {
            return true;
        }

        $normalized = mb_strtolower($userAgent);
        if (1 === preg_match('/(?:^|[^[:alnum:]])bot(?:$|[^[:alnum:]])/u', $normalized)) {
            return true;
        }

        foreach (self::BOT_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }
}
