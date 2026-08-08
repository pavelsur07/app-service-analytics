<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\RegisterCompanyWithOzonAccountAction;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Identity\Infrastructure\Crypto\CredentialsCipher;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Доказывает связку конструктора Entity + шифрования + реального Postgres:
 * не мок репозитория (ADR-005 отвергает моки репозиториев — они проверяют
 * настройку мока, а не то, что запрос работает).
 */
final class RegisterCompanyWithOzonAccountActionTest extends KernelTestCase
{
    public function testCreatesCompanyAndOzonAccountWithRoundTrippableCredentials(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var RegisterCompanyWithOzonAccountAction $action */
        $action = $container->get(RegisterCompanyWithOzonAccountAction::class);
        /** @var CredentialsCipher $cipher */
        $cipher = $container->get(CredentialsCipher::class);

        $account = ($action)('Sandbox LLC', 'shop-1', ['client_id' => 'shop-1', 'api_key' => 'k-1']);

        self::assertSame(MarketplaceAccountState::Active, $account->state());

        $decrypted = $cipher->decrypt($account->credentialsCiphertext(), $account->credentialsKeyVersion());
        self::assertSame(['client_id' => 'shop-1', 'api_key' => 'k-1'], $decrypted->toArray());
    }

    public function testDuplicateMarketplaceAccountForSameCompanyIsRejected(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var RegisterCompanyWithOzonAccountAction $action */
        $action = $container->get(RegisterCompanyWithOzonAccountAction::class);

        ($action)('Sandbox LLC', 'shop-2', ['client_id' => 'shop-2', 'api_key' => 'k-2']);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        $this->expectException(UniqueConstraintViolationException::class);

        // Тот же company_id создать заново нельзя намеренно — здесь важен
        // сам факт, что уникальный индекс (company_id, marketplace,
        // external_shop_id) реален на уровне БД, а не только в Doctrine-мэппинге.
        $entityManager->getConnection()->insert('marketplace_account', [
            'id' => (string) \Symfony\Component\Uid\Uuid::v7(),
            'company_id' => $entityManager->getConnection()
                ->fetchOne('SELECT company_id FROM marketplace_account WHERE external_shop_id = ?', ['shop-2']),
            'marketplace' => 'ozon',
            'external_shop_id' => 'shop-2',
            'credentials_ciphertext' => 'stub',
            'credentials_key_version' => 1,
            'state' => 'active',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
