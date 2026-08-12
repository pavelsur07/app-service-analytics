<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Domain\ValueObject\ExtensionTokenSecret;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Учётные данные расширения браузера (ADR-010, уточняет ADR-007).
 * Привязка к паре «компания + пользователь» неизменяема: расширение
 * работает с одной компанией, смена компании — перевыпуск.
 *
 * Строки не удаляются никогда, даже после отзыва: created_at, user_id,
 * revoked_at, revoked_by_user_id и last_seen_at и есть след выпуска
 * и отзыва (ADR-010). Общий аудит-журнал ADR-007 остаётся открытым долгом.
 *
 * Хранится только хэш секрета — сам секрет отдаётся один раз при выпуске
 * и в базе не появляется (ExtensionTokenSecret).
 */
#[ORM\Entity]
#[ORM\Table(name: 'extension_token')]
#[ORM\UniqueConstraint(name: 'uq_extension_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_extension_token_company_user', columns: ['company_id', 'user_id'])]
class ExtensionToken
{
    /**
     * Срок жизни (ADR-010): утёкший токен умирает сам за месяц. Refresh-токена
     * нет — он появится, только если срок придётся сокращать.
     */
    public const string TTL = 'P30D';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $userId;

    // options: ['fixed' => true] — sha256 в hex всегда ровно 64 символа,
    // char(64), не varchar: та же причина, что у currency в ADR-004.
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private readonly string $tokenHash;

    #[ORM\Column(length: 32)]
    private readonly string $tokenPrefix;

    #[ORM\Column]
    private readonly \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private readonly \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    // Без индекса намеренно: поле следа, по нему не фильтруют и не
    // соединяют — ни один запрос не начинается с «кто отзывал».
    // Индекс, которым не пользуются, оплачивается на каждой записи.
    // Правило §6 требует индекс для ссылок на сущности другого модуля;
    // User — тот же Identity, и запросов по этой колонке нет.
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $revokedByUserId = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    private function __construct(
        Uuid $id,
        Uuid $companyId,
        Uuid $userId,
        string $tokenHash,
        string $tokenPrefix,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->id = $id;
        $this->companyId = $companyId;
        $this->userId = $userId;
        $this->tokenHash = $tokenHash;
        $this->tokenPrefix = $tokenPrefix;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
    }

    /**
     * $issuedAt параметром, а не `new \DateTimeImmutable()` внутри (в отличие
     * от User::register): срок истечения — то, что проверяет тест, и Builder
     * обязан уметь его задать, не вычисляя сам (ADR-005).
     */
    public static function issue(
        Uuid $companyId,
        Uuid $userId,
        ExtensionTokenSecret $secret,
        \DateTimeImmutable $issuedAt,
    ): self {
        return new self(
            Uuid::v7(),
            $companyId,
            $userId,
            $secret->hash(),
            $secret->displayPrefix(),
            $issuedAt,
            $issuedAt->add(new \DateInterval(self::TTL)),
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

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function tokenPrefix(): string
    {
        return $this->tokenPrefix;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function lastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    /**
     * Отзыва как метода сущности здесь намеренно нет.
     *
     * Проверка «если ещё не отозван — записать» в PHP защитой не является
     * по той же причине, что и в CLAUDE.md §4: два параллельных запроса
     * проходят её оба, каждый в своей транзакции, и второй затирает
     * первого отзывающего. Обещание ADR-010 «след не переписывается
     * задним числом» тогда не выполняется.
     *
     * Поэтому отзыв — условный UPDATE с `revoked_at IS NULL` в самом
     * запросе: ExtensionTokenRepository::revokeIfActive(). Первый писатель
     * побеждает в базе, второй не меняет ни одной строки.
     */
    public function markSeen(\DateTimeImmutable $at): void
    {
        $this->lastSeenAt = $at;
    }
}
