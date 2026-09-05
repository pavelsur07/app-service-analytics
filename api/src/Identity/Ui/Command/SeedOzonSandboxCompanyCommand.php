<?php

declare(strict_types=1);

namespace App\Identity\Ui\Command;

use App\Identity\Application\RegisterCompanyWithOzonAccountAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Заводит компанию и Ozon-подключение для ручного тестирования tracer
 * bullet. Саморегистрация отложена ADR-007 — это единственный способ
 * завести учётку на текущей стадии.
 */
#[AsCommand(
    name: 'app:identity:seed-ozon-sandbox-company',
    description: 'Создаёт компанию и Ozon-подключение с указанными учётными данными',
)]
final class SeedOzonSandboxCompanyCommand extends Command
{
    public function __construct(
        private readonly RegisterCompanyWithOzonAccountAction $registerCompanyWithOzonAccount,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyName', InputArgument::REQUIRED, 'Название компании')
            ->addArgument('externalShopId', InputArgument::REQUIRED, 'Идентификатор кабинета Ozon (Client-Id)')
            ->addArgument('apiKey', InputArgument::REQUIRED, 'Ozon Api-Key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $companyName */
        $companyName = $input->getArgument('companyName');
        /** @var string $externalShopId */
        $externalShopId = $input->getArgument('externalShopId');
        /** @var string $apiKey */
        $apiKey = $input->getArgument('apiKey');

        $account = ($this->registerCompanyWithOzonAccount)(
            companyName: $companyName,
            name: $companyName,
            externalShopId: $externalShopId,
            credentials: ['client_id' => $externalShopId, 'api_key' => $apiKey],
        );

        $io->success(\sprintf(
            'Компания "%s" и Ozon-подключение %s созданы. companyId=%s marketplaceAccountId=%s',
            $companyName,
            $externalShopId,
            $account->companyId(),
            $account->id(),
        ));

        return Command::SUCCESS;
    }
}
