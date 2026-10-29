<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Command;

use App\SalesPlanning\Application\CaptureSalesPlanForwardAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:sales-planning:capture-forward', description: 'Publish one bounded private plan-linked forward artifact.')]
final class CaptureSalesPlanForwardCommand extends Command
{
    public function __construct(private readonly CaptureSalesPlanForwardAction $capture)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        foreach (['company', 'account', 'plan', 'request', 'from', 'to'] as $argument) {
            $this->addArgument($argument, InputArgument::REQUIRED);
        }
        $this->addOption('quality-protocol', null, InputOption::VALUE_REQUIRED);
        $this->addOption('quality-protocol-hash', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $args = [];
        foreach (['company', 'account', 'plan', 'request', 'from', 'to'] as $name) {
            $value = $input->getArgument($name);
            if (!\is_string($value)) {
                throw new \InvalidArgumentException('Capture arguments must be strings.');
            }
            $args[$name] = $value;
        }
        $protocolId = $input->getOption('quality-protocol');
        $protocolHash = $input->getOption('quality-protocol-hash');
        if ((null === $protocolId) !== (null === $protocolHash) || (null !== $protocolId && (!\is_string($protocolId) || !\is_string($protocolHash)))) {
            throw new \InvalidArgumentException('Quality protocol identity and hash are required together.');
        }
        $ref = ($this->capture)($args['company'], $args['account'], $args['plan'], $args['request'], $args['from'], $args['to'], qualityProtocolId: \is_string($protocolId) ? $protocolId : null, qualityProtocolHash: \is_string($protocolHash) ? $protocolHash : null);
        $output->writeln(json_encode(['kind' => $ref->kind, 'requestId' => $ref->requestId, 'artifactHash' => $ref->artifactHash, 'actualReadAt' => $ref->actualReadAt->format('Y-m-d\TH:i:s.u\Z'), 'chunks' => $ref->chunks], \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
