<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Command;

use App\Ingestion\Application\NetForecast as Net;
use App\Ingestion\Domain\NetForwardArtifactKind;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:ingestion:net-forecast-quality', description: 'Freeze, select and evaluate an exact private prospective protocol.')]
final class EvaluateNetForecastQualityCommand extends Command
{
    public function __construct(private readonly Net\FreezeNetQualityProtocolAction $freeze, private readonly Net\SealNetQualityInventoryAction $seal, private readonly Net\SelectNetQualityObservationsAction $select, private readonly Net\EvaluateNetForecastQualityAction $evaluate, private readonly Net\NetQualityInventoryReader $inventories)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('mode', InputArgument::REQUIRED, 'freeze|seal-inventory|select|evaluate');
        foreach (['company', 'account', 'operation', 'request-file', 'request-hash', 'selection', 'selection-hash', 'truth-inventory', 'truth-inventory-hash'] as $name) {
            $this->addOption($name, null, InputOption::VALUE_REQUIRED);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $mode = $input->getArgument('mode');
            if (!\in_array($mode, ['freeze', 'seal-inventory', 'select', 'evaluate'], true)) {
                throw new \InvalidArgumentException('Invalid mode.');
            }
            $company = Net\NetQualitySchema::uuid($this->option($input, 'company'));
            $account = Net\NetQualitySchema::uuid($this->option($input, 'account'));
            $operation = Net\NetQualitySchema::uuid($this->option($input, 'operation'), true);
            $fileOptions = ['request-file', 'request-hash'];
            $evaluateOptions = ['selection', 'selection-hash', 'truth-inventory', 'truth-inventory-hash'];
            foreach ('evaluate' === $mode ? $fileOptions : $evaluateOptions as $forbidden) {
                if (null !== $input->getOption($forbidden)) {
                    throw new \InvalidArgumentException('Invalid option combination.');
                }
            }
            if ('evaluate' === $mode) {
                $selection = new Net\NetQualityArtifactRef(NetForwardArtifactKind::QualitySelection, $this->option($input, 'selection'), $this->option($input, 'selection-hash'));
                $truth = new Net\NetQualityArtifactRef(NetForwardArtifactKind::QualityInventory, $this->option($input, 'truth-inventory'), $this->option($input, 'truth-inventory-hash'));
                $budget = new Net\NetQualityBudget();
                $inventory = $this->inventories->read($company, $account, $truth, $budget);
                if (!$inventory instanceof Net\NetQualityTruthInventory) {
                    throw new \InvalidArgumentException('Expected truth inventory.');
                }
                $result = ($this->evaluate)($company, $account, $selection, $inventory, $operation, $budget);
                $payload = ['dataset' => $result->datasetRef->toArray(), 'report' => $result->reportRef->toArray(), 'decision' => $result->decision, 'counts' => $result->counts, 'numericRolloutAllowed' => false];
            } else {
                $data = $this->request($this->option($input, 'request-file'), $this->option($input, 'request-hash'));
                if (($data['freeze' === $mode ? 'protocolId' : 'requestId'] ?? null) !== $operation) {
                    throw new \InvalidArgumentException('Operation identity mismatch.');
                }
                if ('freeze' === $mode) {
                    $ref = ($this->freeze)($company, $account, new Net\NetQualityProtocol($data));
                } elseif ('seal-inventory' === $mode) {
                    $ref = ($this->seal)($company, $account, new Net\NetQualityInventoryRequest($data));
                } else {
                    Net\NetQualitySchema::keys($data, ['requestId', 'protocol', 'inventory']);
                    if (!\is_array($data['protocol']) || !\is_array($data['inventory'])) {
                        throw new \InvalidArgumentException('Selection references missing.');
                    }
                    $ref = ($this->select)($company, $account, new Net\NetQualitySelectionRequest($operation, Net\NetQualityArtifactRef::fromArray($data['protocol']), Net\NetQualityArtifactRef::fromArray($data['inventory'])));
                }
                $payload = ['artifact' => $ref->toArray(), 'status' => 'sealed'];
            }
            $output->writeln(json_encode($payload, \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        } catch (\InvalidArgumentException|\JsonException) {
            $output->writeln('{"status":"invalid_request"}');

            return Command::INVALID;
        } catch (\Throwable) {
            // Storage keys, identities, private file bodies and DB errors never enter stdout.
            $output->writeln('{"status":"incomplete","numericRolloutAllowed":false}');

            return Command::FAILURE;
        }
    }

    private function option(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        if (!\is_string($value) || '' === $value) {
            throw new \InvalidArgumentException('Missing required option.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function request(string $path, string $hash): array
    {
        if (str_contains($path, '://') || str_contains($path, "\0") || 1 !== preg_match('/^[a-f0-9]{64}$/D', $hash) || !is_file($path)) {
            throw new \InvalidArgumentException('Expected a private local request file and SHA256.');
        }
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            throw new \InvalidArgumentException('Cannot read request.');
        }
        try {
            $stat = fstat($handle);
            if (false === $stat || 0100000 !== ($stat['mode'] & 0170000) || 0 !== ($stat['mode'] & 0077) || $stat['size'] < 1 || $stat['size'] > 1048576) {
                throw new \InvalidArgumentException('Request file must be private and bounded.');
            }
            $body = stream_get_contents($handle, 1048577);
        } finally {
            fclose($handle);
        }
        if (false === $body || \strlen($body) > 1048576 || !hash_equals($hash, hash('sha256', $body))) {
            throw new \InvalidArgumentException('Request hash or size mismatch.');
        }
        $data = json_decode($body, true, 40, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        if (!\is_array($data)) {
            throw new \InvalidArgumentException('Request requires a JSON object.');
        }
        // Canonical validation rejects floats and arbitrary object values before artifact I/O.
        (new Net\NetForwardManifestCodec())->encode($data);

        return Net\NetQualitySchema::rows([$data], 1)[0];
    }
}
