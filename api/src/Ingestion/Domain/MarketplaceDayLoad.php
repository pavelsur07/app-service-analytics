<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Отметка загрузки дня одной выгрузки по подключению (ADR-038).
 *
 * Raw-документ сохраняется до разбора, и по нему одному день, на котором
 * разбор или сеть отказали посреди страниц, выглядит загруженным.
 * Отметка говорит, чем кончился последний прогон дня: `completed_at` —
 * время последнего успеха (все страницы записаны), `failed_at` и
 * `failure` — отказ после него. Успех отказ стирает; день отклонён, пока
 * `failed_at` не пуст (`DayLoadSql`).
 *
 * Отметки нет — день загружен до ADR-038, о нём судят по raw.
 *
 * Пишется только DoctrineMarketplaceDayLoadWriter, upsert по ключу;
 * ORM persist/flush не используется (CLAUDE.md §6).
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_day_load')]
// Межарендаторное чтение сторожа свежести (RecentlyIngestedAccountsQuery)
// отбирает по времени успеха — ведущий столбец completed_at, остальные
// (с period — отбор недавнего дня) ради index-only scan (CLAUDE.md §1,
// «Индекс следует за запросом»).
#[ORM\Index(name: 'idx_marketplace_day_load_completed_at', columns: ['completed_at', 'company_id', 'marketplace_account_id', 'report_type', 'period'])]
class MarketplaceDayLoad
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private readonly string $reportType;

    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $period;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $failedAt = null;

    /** Текст отказа — для разбора «почему день неполный», без тела ответа. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $failure = null;

    private function __construct(Uuid $companyId, Uuid $marketplaceAccountId, string $reportType, \DateTimeImmutable $period)
    {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->reportType = $reportType;
        $this->period = $period;
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

    public function completedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function failedAt(): ?\DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function failure(): ?string
    {
        return $this->failure;
    }
}
