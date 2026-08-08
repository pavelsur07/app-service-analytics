<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Ответ внешнего API целиком, до какого-либо разбора (ADR-006). Никогда
 * не мутируется — обновлений и сеттеров нет, только создание.
 *
 * `period` — бизнес-дата, которую представляет этот фетч, не буквальные
 * since/to запроса. since/to всегда меняются между запусками синхронизации
 * (to обычно «сейчас»), и если бы они входили в ключ уникальности, повторная
 * загрузка идентичного дня каждый раз считалась бы новым периодом — дедуп
 * по body_hash никогда бы не сработал. period — тот стабильный бакет,
 * относительно которого «идентичный ответ» вообще имеет смысл.
 *
 * Не пишется ORM (persist/flush): пайплайн, а не человек, кладёт эти строки
 * (CLAUDE.md §6) — запись через DBAL, DoctrineMarketplaceRawDocumentRepository.
 * Класс существует для migrations:diff/schema:validate/Builder тестов.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_raw_document')]
#[ORM\UniqueConstraint(
    name: 'uq_marketplace_raw_document_natural_key',
    columns: ['company_id', 'marketplace_account_id', 'report_type', 'period', 'body_hash'],
)]
#[ORM\Index(name: 'idx_marketplace_raw_document_company_id', columns: ['company_id'])]
class MarketplaceRawDocument
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    #[ORM\Column(length: 64)]
    private readonly string $reportType;

    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $period;

    #[ORM\Column(length: 64)]
    private readonly string $bodyHash;

    /** @var array<array-key, mixed> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private readonly array $body;

    #[ORM\Column]
    private readonly \DateTimeImmutable $receivedAt;

    /**
     * @param array<array-key, mixed> $body
     */
    private function __construct(
        Uuid $id,
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $reportType,
        \DateTimeImmutable $period,
        string $bodyHash,
        array $body,
        \DateTimeImmutable $receivedAt,
    ) {
        $this->id = $id;
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->reportType = $reportType;
        $this->period = $period;
        $this->bodyHash = $bodyHash;
        $this->body = $body;
        $this->receivedAt = $receivedAt;
    }

    /**
     * $rawBody — точные байты HTTP-ответа (ADR-006: хэш и содержимое
     * относятся к тому, что реально пришло по сети, не к пересобранной
     * строке). Площадка предполагается отдающей синтаксически валидный
     * JSON даже на ошибках — устойчивость raw-слоя к дрейфу схем
     * (ADR-006) про переименованные/пропавшие поля структуры ответа,
     * не про повреждённые байты на транспорте.
     */
    public static function capture(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $reportType,
        \DateTimeImmutable $period,
        string $rawBody,
    ): self {
        $decoded = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException('Ozon response body must decode to a JSON object or array.');
        }

        return new self(
            Uuid::v7(),
            $companyId,
            $marketplaceAccountId,
            $reportType,
            $period,
            hash('sha256', $rawBody),
            $decoded,
            new \DateTimeImmutable(),
        );
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function marketplaceAccountId(): Uuid
    {
        return $this->marketplaceAccountId;
    }

    public function reportType(): string
    {
        return $this->reportType;
    }

    public function period(): \DateTimeImmutable
    {
        return $this->period;
    }

    public function bodyHash(): string
    {
        return $this->bodyHash;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    public function receivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }
}
