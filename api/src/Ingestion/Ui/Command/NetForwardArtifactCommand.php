<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Application\NetForecast\CaptureNetForecastTruthAction;
use App\Ingestion\Application\NetForecast\ReplayNetForecastArtifactAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:ingestion:forward-artifact', description: 'Replay an exact private input or publish one new truth observation.')]
final class NetForwardArtifactCommand extends Command
{
    public function __construct(private readonly ReplayNetForecastArtifactAction $replay, private readonly CaptureNetForecastTruthAction $truth)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        foreach (['mode', 'company', 'account', 'input', 'hash'] as $argument) {
            $this->addArgument($argument, InputArgument::REQUIRED);
        }
        $this->addArgument('truth-request', InputArgument::OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $args = [];
        foreach (['mode', 'company', 'account', 'input', 'hash'] as $name) {
            $value = $input->getArgument($name);
            if (!\is_string($value)) {
                throw new \InvalidArgumentException('Artifact arguments must be strings.');
            }
            $args[$name] = $value;
        }
        if ('replay' === $args['mode']) {
            ($this->replay)($args['company'], $args['account'], $args['input'], $args['hash']);
            $result = ['requestId' => $args['input'], 'artifactHash' => $args['hash'], 'replayVerified' => true];
        } elseif ('truth' === $args['mode']) {
            $request = $input->getArgument('truth-request');
            if (!\is_string($request)) {
                throw new \InvalidArgumentException('Truth requires an explicit request UUIDv7.');
            }
            $artifact = ($this->truth)($args['company'], $args['account'], $args['input'], $args['hash'], $request);
            $result = ['requestId' => $artifact->publication->root->requestId, 'artifactHash' => $artifact->publication->root->sha256, 'actualReadAt' => $artifact->readAt->format('Y-m-d\TH:i:s.u\Z')];
        } else {
            throw new \InvalidArgumentException('Artifact mode must be replay or truth.');
        }
        $output->writeln(json_encode($result, \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
