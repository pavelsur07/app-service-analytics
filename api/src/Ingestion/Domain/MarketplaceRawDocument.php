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
// Контроль свежести данных (NotifyStaleAccountsAction): диапазон
// по received_at с группировкой по подключению и типу отчёта. Порядок
// столбцов — ради index-only scan, см. RecentlyIngestedAccountsQuery.
//
// report_type дописан четвёртым, а не поставлен первым: ведущим столбцом
// обязан остаться диапазон по времени — это единственное неравенство
// в запросе, и всё, что встанет перед ним, придётся сканировать целиком.
// Отслеживаемых типов сегодня два из трёх, отсечение по ним экономит мало.
#[ORM\Index(name: 'idx_marketplace_raw_document_received_at', columns: ['received_at', 'company_id', 'marketplace_account_id', 'report_type'])]
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

    #[ORM\Column(type: 'text')]
    private readonly string $body;

    #[ORM\Column]
    private readonly \DateTimeImmutable $receivedAt;

    private function __construct(
        Uuid $id,
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $reportType,
        \DateTimeImmutable $period,
        string $bodyHash,
        string $body,
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
     * $rawBody — точные байты HTTP-ответа, сохраняются как есть, без
     * попытки разбора: ADR-006 требует, чтобы raw переживал ошибку
     * разбора, а не только дрейф структуры внутри валидного JSON.
     * Требование валидности здесь сделало бы синтаксически некорректный
     * (или не-JSON) ответ невозможным сохранить вообще — ровно то,
     * от чего raw-слой обязан защищать. Разбор — отдельный шаг
     * (пакет 4), его неудача не отменяет уже сохранённую запись.
     */
    public static function capture(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $reportType,
        \DateTimeImmutable $period,
        string $rawBody,
        // Момент получения — снаружи, а не только из часов внутри:
        // по нему меряется свежесть данных (NotifyStaleAccountsAction),
        // и тест обязан задавать проверяемое значение сам (ADR-005).
        // Умолчание оставлено, чтобы боевой вызов не повторял «сейчас».
        ?\DateTimeImmutable $receivedAt = null,
    ): self {
        return new self(
            Uuid::v7(),
            $companyId,
            $marketplaceAccountId,
            $reportType,
            $period,
            hash('sha256', $rawBody),
            $rawBody,
            $receivedAt ?? new \DateTimeImmutable(),
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

    public function body(): string
    {
        return $this->body;
    }

    public function receivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }
}
