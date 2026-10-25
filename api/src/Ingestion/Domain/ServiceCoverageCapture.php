<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Последняя снятая сводка покрытия сервиса вне монолита (контракт
 * service-coverage v1, ADR-043 п. 6) — для экрана «Покрытие GO» админки.
 *
 * Снимает её отдельный процесс по расписанию
 * (CaptureServiceCoverageCommand): синхронного вызова сервиса из запроса
 * пользователя нет (services/AGENTS.md §2, решение владельца 25.10.2026).
 * Строка — одна на сервис: `state` и `attempted_at` — исход последней
 * попытки, `payload` и `succeeded_at` — последняя удачная сводка; отказ
 * её не стирает, экран показывает её вместе со временем.
 *
 * Данных компаний в строке нет: сводка — только счётчики по ресурсу
 * и периоду. Пишется только DbalServiceCoverageCaptureStore, upsert
 * по ключу; ORM persist/flush не используется (CLAUDE.md §6).
 */
#[ORM\Entity]
#[ORM\Table(name: 'service_coverage_capture')]
class ServiceCoverageCapture
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private readonly string $service;

    /** ok | disabled | not_ready | unavailable — ServiceCoverageState */
    #[ORM\Column(length: 16)]
    private string $state;

    #[ORM\Column]
    private \DateTimeImmutable $attemptedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $succeededAt = null;

    /**
     * Ответ сервиса последней удачной попытки, прошедший разбор по схеме.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $payload = null;

    private function __construct(string $service, string $state, \DateTimeImmutable $attemptedAt)
    {
        $this->service = $service;
        $this->state = $state;
        $this->attemptedAt = $attemptedAt;
    }

    public function service(): string
    {
        return $this->service;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function attemptedAt(): \DateTimeImmutable
    {
        return $this->attemptedAt;
    }

    public function succeededAt(): ?\DateTimeImmutable
    {
        return $this->succeededAt;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(): ?array
    {
        return $this->payload;
    }
}
