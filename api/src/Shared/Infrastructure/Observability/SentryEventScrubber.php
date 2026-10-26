<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Вырезает заголовки-кандидаты на секрет площадок (Api-Key, Authorization,
 * Client-Id) из breadcrumbs перед отправкой в GlitchTip. При tracing:
 * enabled: false (config/packages/sentry.yaml) breadcrumbs с такими
 * ключами сейчас не возникают — это подстраховка на случай, если
 * зависимость или конфигурация в будущем изменятся, не реакция
 * на существующую утечку.
 */
final readonly class SentryEventScrubber
{
    /** @var list<string> */
    private const array SENSITIVE_KEYS = ['api-key', 'authorization', 'client-id', 'secret', 'provisioninguri', 'recoverycodes', 'recovery_codes', 'recovery_code', 'mfa_secret', 'totp_secret'];

    public function __invoke(Event $event, ?EventHint $hint): Event
    {
        // send_default_pii не управляет сбором тела в установленном SDK.
        // Тела с паролями/кодами не отправляются независимо от их формы.
        $request = $event->getRequest();
        unset($request['data']);
        $event->setRequest($request);
        $extra = [];
        foreach ($event->getExtra() as $key => $value) {
            $extra[$key] = $this->scrubValue($value, $key);
        }
        $event->setExtra($extra);
        $event->setBreadcrumb(array_map($this->scrubBreadcrumb(...), $event->getBreadcrumbs()));

        return $event;
    }

    private function scrubBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        foreach ($breadcrumb->getMetadata() as $key => $value) {
            $breadcrumb = $breadcrumb->withMetadata($key, $this->scrubValue($value, $key));
        }

        return $breadcrumb;
    }

    private function scrubValue(mixed $value, string $key): mixed
    {
        if (\in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
            return '[scrubbed]';
        }
        if (\is_array($value)) {
            $scrubbed = [];
            foreach ($value as $nestedKey => $nestedValue) {
                $scrubbed[$nestedKey] = $this->scrubValue($nestedValue, \is_string($nestedKey) ? $nestedKey : '');
            }

            return $scrubbed;
        }

        return $value;
    }
}
