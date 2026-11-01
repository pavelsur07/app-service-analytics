<?php

declare(strict_types=1);

use App\Kernel;
use App\ProductResearchOzon\Application\GetResearchRunAction;
use App\ProductResearchOzon\Application\ProcessResearchRunsAction;
use App\ProductResearchOzon\Application\RelayResearchRunAction;
use App\ProductResearchOzon\Application\ResearchPreparation;
use App\ProductResearchOzon\Application\StartResearchRunAction;
use App\ProductResearchOzon\Application\SynchronizeResearchRunAction;
use App\ProductResearchOzon\Domain\ResearchProjectRepository;
use App\ProductResearchOzon\Domain\ResearchRunRepository;
use App\ProductResearchOzon\Domain\RunDeliveryRepository;
use App\ProductResearchOzon\Domain\RunNotifier;
use App\ProductResearchOzon\Infrastructure\Api\HttpRunSource;
use App\ProductResearchOzon\Infrastructure\Config\CatalogContext;
use App\ProductResearchOzon\Infrastructure\Query\ResearchRunsQuery;
use App\Tests\Support\Builder\ResearchProjectBuilder;
use App\Tests\Support\Fake\ResearchClock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';

if ('test' !== getenv('APP_ENV')) {
    throw new RuntimeException('test_only');
}
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
(new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
if (!$container instanceof Symfony\Component\DependencyInjection\ContainerInterface) {
    throw new RuntimeException('test_container');
}
$db = $container->get(Connection::class);
if (!$db instanceof Connection || 'app_test' !== $db->fetchOne('SELECT current_database()')) {
    throw new RuntimeException('test_database');
}
$line = fgets(\STDIN);
$input = false === $line ? null : json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
if (!is_array($input) || !is_string($input['company'] ?? null) || !is_string($input['source'] ?? null)) {
    throw new RuntimeException('test_input');
}
$company = $input['company'];
$source = $input['source'];
if (!Uuid::isValid($company) || !Uuid::isValid($source) || false !== $db->fetchOne('SELECT id FROM company WHERE id = ?', [$company])) {
    throw new RuntimeException('synthetic_company_required');
}
/**
 * @template T of object
 *
 * @param class-string<T> $class
 *
 * @return T
 */
function service(Symfony\Component\DependencyInjection\ContainerInterface $container, string $class): object
{
    $value = $container->get($class);
    if (!$value instanceof $class) {
        throw new RuntimeException('test_service');
    }

    return $value;
}
$project = ResearchProjectBuilder::aProject()->withCompanyId(Uuid::fromString($company))->persistWith(service($container, ResearchProjectRepository::class));
$notifier = new class implements RunNotifier {
    public function notify(string $companyId, string $runId): void
    {
    }
};
$start = new StartResearchRunAction(service($container, ResearchProjectRepository::class), service($container, ResearchRunRepository::class), service($container, ResearchRunsQuery::class), service($container, ResearchPreparation::class), new CatalogContext($source), service($container, App\Identity\Application\Facade\IdentityFacade::class), $notifier, service($container, GetResearchRunAction::class));
$view = $start($company, $project->id()->toRfc4122(), Uuid::v7()->toRfc4122(), 1, '2026-04-15', Uuid::v7()->toRfc4122());
$client = new class(HttpClient::create()) implements HttpClientInterface {
    public function __construct(private HttpClientInterface $inner)
    {
    }

    /** @param array<array-key, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $prefix = 'http://product-research-ozon:8080/';
        if (!str_starts_with($url, $prefix)) {
            throw new RuntimeException('test_destination');
        }

        return $this->inner->request($method, 'http://research-stage5-http:8080/'.substr($url, strlen($prefix)), $options);
    }

    /** @param ResponseInterface|iterable<array-key, ResponseInterface> $responses */
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /** @param array<array-key, mixed> $options */
    public function withOptions(array $options): static
    {
        $copy = clone $this;
        $copy->inner = $this->inner->withOptions($options);

        return $copy;
    }
};
$remote = new HttpRunSource($client, 'stage5-http-'.str_repeat('x', 40), new CatalogContext($source));
$repo = service($container, RunDeliveryRepository::class);
$clock = service($container, ResearchClock::class);
$process = new ProcessResearchRunsAction(service($container, ResearchRunsQuery::class), new RelayResearchRunAction($repo, $remote, $clock), new SynchronizeResearchRunAction($repo, $remote, $clock), $clock);
$deadline = microtime(true) + 20;
do {
    $clock->freeze($clock->now()->modify('+60 seconds')->format(\DATE_ATOM));
    $process($company);
    $view = service($container, GetResearchRunAction::class)($company, $project->id()->toRfc4122(), $view->progress->runId);
    if ('ready' === $view->progress->status) {
        break;
    }
    usleep(100000);
} while (microtime(true) < $deadline);
if ('ready' !== $view->progress->status || 3 !== count($view->progress->months) || !$view->delivered) {
    throw new RuntimeException('http_smoke_failed');
}
fwrite(\STDOUT, "PHP intent → Python HTTP → PostgreSQL/S3 → PHP progress: 3 ready months OK\n");
