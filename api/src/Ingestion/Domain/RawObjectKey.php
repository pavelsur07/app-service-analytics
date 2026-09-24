<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Ключ объекта сырья в S3 (ADR-024):
 *
 *   conwix/raw/v1/companies/{company}/accounts/{account}/{report_type}/{YYYY}/{MM}/{DD}/{sha256}.{ext}.gz
 *
 * Компания — первый сегмент после префикса: данные арендатора удаляются,
 * выгружаются и закрываются правами одним префиксом, и смешать две
 * компании в одной «папке» нельзя по построению.
 *
 * Ключ детерминирован теми же полями, что уникальный индекс
 * marketplace_raw_document (компания, подключение, тип, период, хэш тела):
 * повторная загрузка того же ответа попадает в тот же объект, а не
 * создаёт второй. Дата — бизнес-период документа, не время получения.
 */
final readonly class RawObjectKey
{
    public const string PREFIX = 'conwix/raw/v1';

    private function __construct(
        private string $key,
    ) {
    }

    public static function for(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $reportType,
        \DateTimeImmutable $period,
        string $bodyHash,
        string $extension,
    ): self {
        // Сегменты попадают в путь как есть: слэш или точка в типе отчёта
        // увели бы объект в чужой префикс. Модификатор D: без него `$`
        // пропускает завершающий перевод строки.
        if (1 !== preg_match('/^[a-z0-9_]+$/D', $reportType)) {
            throw new \InvalidArgumentException(\sprintf('Тип отчёта «%s» не годится для ключа объекта.', $reportType));
        }
        if (1 !== preg_match('/^[0-9a-f]{64}$/D', $bodyHash)) {
            throw new \InvalidArgumentException('Хэш тела — sha256 в нижнем регистре, 64 символа.');
        }
        if (1 !== preg_match('/^[a-z0-9]+$/D', $extension)) {
            throw new \InvalidArgumentException(\sprintf('Расширение «%s» не годится для ключа объекта.', $extension));
        }

        return new self(\sprintf(
            '%s/companies/%s/accounts/%s/%s/%s/%s.%s.gz',
            self::PREFIX,
            $companyId->toRfc4122(),
            $marketplaceAccountId->toRfc4122(),
            $reportType,
            $period->format('Y/m/d'),
            $bodyHash,
            $extension,
        ));
    }

    public function toString(): string
    {
        return $this->key;
    }
}
