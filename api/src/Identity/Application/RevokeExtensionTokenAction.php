<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ExtensionTokenRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Отзыв токена расширения (ADR-010). Запись не удаляется — проставляется
 * revoked_at и кто отозвал: таблица и есть след выпуска и отзыва.
 *
 * Возвращает false, когда токена с таким id в этой компании нет —
 * вызывающий превращает это в 404, не в тихий успех. Повторный отзыв
 * уже отозванного — успех: операция идемпотентна, а первый след
 * защищён условием внутри UPDATE, не проверкой здесь.
 */
final readonly class RevokeExtensionTokenAction
{
    public function __construct(
        private ExtensionTokenRepository $tokens,
    ) {
    }

    public function __invoke(string $companyId, Uuid $tokenId, Uuid $revokedByUserId): bool
    {
        // Существование — отдельным чтением, только чтобы отличить 404
        // от идемпотентного повтора. Сам отзыв на результат этой проверки
        // не опирается: условие живёт внутри UPDATE.
        if (null === $this->tokens->get($companyId, $tokenId)) {
            return false;
        }

        $this->tokens->revokeIfActive($companyId, $tokenId, $revokedByUserId, new \DateTimeImmutable());

        return true;
    }
}
