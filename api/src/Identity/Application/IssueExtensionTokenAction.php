<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ExtensionToken;
use App\Identity\Domain\ExtensionTokenRepository;
use App\Identity\Domain\ValueObject\ExtensionTokenSecret;
use Symfony\Component\Uid\Uuid;

/**
 * Выпуск токена расширения (ADR-010). Членство вызывающего в компании
 * проверено раньше, на границе HTTP (CompanyAccessSubscriber) — Action
 * его не перепроверяет, как и остальные company-scoped сценарии.
 *
 * Прежние токены не отзываются: у одного человека может быть несколько
 * браузеров. Ненужный отзывается явно, поштучно.
 */
final readonly class IssueExtensionTokenAction
{
    public function __construct(
        private ExtensionTokenRepository $tokens,
    ) {
    }

    public function __invoke(Uuid $companyId, Uuid $userId): IssuedExtensionToken
    {
        $secret = ExtensionTokenSecret::generate();
        $token = ExtensionToken::issue($companyId, $userId, $secret, new \DateTimeImmutable());
        $this->tokens->add($token);

        return new IssuedExtensionToken($token, $secret->plaintext());
    }
}
