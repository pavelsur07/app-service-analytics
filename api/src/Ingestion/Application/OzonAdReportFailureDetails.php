<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/** Только ограниченные признаки. Тело, URL и текст исключения наружу не выходят. */
final class OzonAdReportFailureDetails
{
    private const int BODY_LIMIT = 16_384;

    /** @return array<string, int|string|null> */
    public static function describe(\Throwable $failure, int $now, string $companyId, string $hmacKey): array
    {
        $details = [
            'http_status' => null,
            'reason_hint' => 'unknown',
            'reason_hint_source' => 'none',
            'provider_code' => null,
            'body_diagnostic' => 'unavailable',
            'retry_after_kind' => 'missing',
            'retry_after_seconds' => null,
            'provider_request_ref' => null,
        ];
        if (!$failure instanceof HttpExceptionInterface) {
            return $details;
        }
        try {
            $response = $failure->getResponse();
            $details['http_status'] = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $retry = $headers['retry-after'] ?? [];
            [$details['retry_after_kind'], $details['retry_after_seconds']] = self::retryAfter($retry, $now);
            $requestIds = $headers['x-request-id'] ?? [];
            if (1 === \count($requestIds) && \strlen($requestIds[0]) <= 128 && 1 === preg_match('/\A[\x21-\x7e]+\z/', $requestIds[0])) {
                $details['provider_request_ref'] = hash_hmac('sha256', "ozon-report-request:v1\0".$companyId."\0".$requestIds[0], $hmacKey);
            }
            $contentTypes = $headers['content-type'] ?? [];
            if (1 !== \count($contentTypes) || \strlen($contentTypes[0]) > 128 || 1 !== preg_match('/\Aapplication\/(?:[a-z0-9.-]+\+)?json(?:\s*;|\z)/i', $contentTypes[0])) {
                $details['body_diagnostic'] = 'unsupported_content_type';

                return $details;
            }
            // Symfony уже буферизует JSON-тело при создании HTTP exception.
            // Не вызывать getContent(): он материализует всё тело. У штатного
            // Symfony-клиента buffered response допускает независимое чтение.
            if (!$response instanceof StreamableInterface) {
                return $details;
            }
            $stream = $response->toStream(false);
            try {
                if (0 !== @fseek($stream, 0)) {
                    return $details;
                }
                $body = @stream_get_contents($stream, self::BODY_LIMIT + 1);
            } finally {
                fclose($stream);
            }
            if (false === $body) {
                return $details;
            }
            if (\strlen($body) > self::BODY_LIMIT) {
                $details['body_diagnostic'] = 'oversized';

                return $details;
            }
            try {
                $decoded = json_decode($body, true, 16, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $details['body_diagnostic'] = 'invalid_json';

                return $details;
            }
            $details['body_diagnostic'] = 'json';
            if (!\is_array($decoded)) {
                return $details;
            }
            $code = $decoded['code'] ?? null;
            // Только ограниченный диапазон стандартных RPC-кодов, не строки.
            $details['provider_code'] = \is_int($code) && $code >= 0 && $code <= 16 ? $code : null;
            $error = $decoded['error'] ?? null;
            $candidates = [$decoded['message'] ?? null, \is_array($error) ? ($error['message'] ?? null) : $error];
            $hints = [];
            foreach ($candidates as $text) {
                if (!\is_string($text) || \strlen($text) > 2048) {
                    continue;
                }
                foreach ([
                    'concurrent_report' => '/(?:report.{0,40}already.{0,20}(?:running|generating|in progress)|concurrent.{0,30}report|parallel.{0,30}report|отч[её]т.{0,30}(?:уже формируется|одновременно))/iu',
                    'short_window_limit' => '/(?:limit.{0,50}per (?:second|minute)|requests? per (?:second|minute).{0,30}(?:exceeded|limit)|лимит.{0,40}(?:в секунду|в минуту))/iu',
                    'daily_limit' => '/(?:daily.{0,30}(?:limit|quota)|(?:limit|quota).{0,30}(?:per day|24 hours)|(?:суточн|дневн).{0,15}лимит)/iu',
                ] as $hint => $pattern) {
                    if (1 === preg_match($pattern, $text)) {
                        $hints[$hint] = true;
                    }
                }
            }
            // Конфликтующие/неясные слова не превращаются в диагноз.
            if (1 === \count($hints)) {
                $details['reason_hint'] = array_key_first($hints);
                $details['reason_hint_source'] = 'body_heuristic';
            }
        } catch (\Throwable) {
            // Не маскировать исходный отказ ошибкой диагностики.
        }

        return $details;
    }

    /** @param list<string> $values
     * @return array{string, ?int}
     */
    private static function retryAfter(array $values, int $now): array
    {
        if ([] === $values) {
            return ['missing', null];
        }
        if (1 !== \count($values) || \strlen($values[0]) > 64) {
            return ['invalid', null];
        }
        $value = trim($values[0]);
        if (1 === preg_match('/[\x00-\x1f\x7f]/', $value)) {
            return ['invalid', null];
        }
        if (1 === preg_match('/\A[0-9]{1,10}\z/', $value) && (int) $value <= 2_147_483_647) {
            return ['seconds', (int) $value];
        }
        // IMF-fixdate: основная HTTP-date форма RFC 9110. Не принимать
        // произвольные strtotime-выражения вроде "tomorrow".
        $date = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone('UTC'));
        if (false !== $date && $date->format('D, d M Y H:i:s \G\M\T') === $value) {
            return ['http_date', max(0, $date->getTimestamp() - $now)];
        }

        return ['invalid', null];
    }
}
