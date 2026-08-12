<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\CompanyMemberRepository;
use App\Identity\Domain\ValueObject\ExtensionTokenSecret;
use App\Identity\Infrastructure\Query\ExtensionTokenByHashQuery;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Проверка токена расширения браузера (ADR-010) для штатного
 * access_token-аутентификатора Symfony на firewall `extension`
 * (config/packages/security.yaml). Свой Authenticator не пишется:
 * извлечение заголовка Authorization: Bearer и построение Passport
 * фреймворк делает сам.
 *
 * Единственный потребитель межарендаторного ExtensionTokenByHashQuery
 * (CLAUDE.md §1) — узкий слой Deptrac на обоих концах вызова
 * (api/deptrac.php: IdentityExtensionTokenHandler → IdentityExtensionTokenQuery),
 * оба выведены из широкого IdentityInfrastructure через mustNot, чтобы
 * ни один HTTP-контроллер не получил доступ к поиску без companyId.
 *
 * Членство перепроверяется на каждом запросе, а не только при выпуске:
 * иначе исключённый из компании участник продолжает читать её данные
 * живым токеном до самого истечения срока.
 */
final readonly class ExtensionTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private ExtensionTokenByHashQuery $tokensByHash,
        private CompanyMemberRepository $companyMembers,
        private RequestStack $requestStack,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $row = $this->tokensByHash->findUsable(ExtensionTokenSecret::hashOf($accessToken), new \DateTimeImmutable());
        if (null === $row) {
            // Отозванный, истёкший и несуществующий неотличимы снаружи —
            // отсев целиком в SQL (ExtensionTokenByHashQuery).
            throw new BadCredentialsException('Invalid extension token.');
        }

        if (!$this->companyMembers->existsForUserAndCompany($row->companyId, $row->userId)) {
            throw new BadCredentialsException('Invalid extension token.');
        }

        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            throw new BadCredentialsException('Invalid extension token.');
        }

        $request->attributes->set(ExtensionTokenRequestAttributes::TOKEN_ID, $row->id);
        $request->attributes->set(ExtensionTokenRequestAttributes::COMPANY_ID, $row->companyId);

        return new UserBadge($row->userEmail);
    }
}
