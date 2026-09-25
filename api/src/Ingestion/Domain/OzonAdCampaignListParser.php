<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Разбор `GET /api/client/campaign` (ADR-026).
 *
 * Читаются только поля, нужные пробе ключа, и каждое обязательно:
 * пропавшее поле — ошибка разбора, а не пустое значение. Лишние ключи
 * допускаются: у кампании десятки служебных полей, которые площадка
 * добавляет по ходу, и строгость к ним сломала бы подключение рекламы,
 * ничего не защитив.
 */
final class OzonAdCampaignListParser
{
    /**
     * @return list<OzonAdCampaign>
     */
    public function parse(string $body): array
    {
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !\is_array($decoded['list'] ?? null)) {
            throw new \UnexpectedValueException('Ozon campaign list: no "list" array.');
        }

        $campaigns = [];
        foreach ($decoded['list'] as $item) {
            if (!\is_array($item)) {
                throw new \UnexpectedValueException('Ozon campaign list: item is not an object.');
            }

            $id = $item['id'] ?? null;
            if (!\is_string($id) || 1 !== preg_match('/\A[0-9]{1,20}\z/', $id)) {
                throw new \UnexpectedValueException('Ozon campaign list: id is not a digit string.');
            }

            $createdAt = self::string($item, 'createdAt');
            try {
                $created = new \DateTimeImmutable($createdAt);
            } catch (\Exception) {
                throw new \UnexpectedValueException('Ozon campaign list: createdAt is not a date.');
            }

            $campaigns[] = new OzonAdCampaign(
                id: $id,
                state: self::string($item, 'state'),
                advObjectType: self::string($item, 'advObjectType'),
                createdAt: $created,
            );
        }

        return $campaigns;
    }

    /**
     * @param array<mixed> $item
     */
    private static function string(array $item, string $key): string
    {
        $value = $item[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new \UnexpectedValueException("Ozon campaign list: \"{$key}\" is missing.");
        }

        return $value;
    }
}
