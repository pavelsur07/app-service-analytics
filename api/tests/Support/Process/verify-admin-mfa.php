<?php

declare(strict_types=1);

use App\Identity\Domain\AdminMfaRepository;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv($root.'/.env');
$input = file_get_contents('php://stdin');
if (false === $input) {
    throw new RuntimeException('Missing concurrent MFA test input.');
}
$value = json_decode($input, true, flags: \JSON_THROW_ON_ERROR);
if (!is_array($value) || !is_string($value['administratorId'] ?? null) || !is_int($value['version'] ?? null) || !is_string($value['code'] ?? null) || !is_string($value['database'] ?? null) || !is_string($value['applicationName'] ?? null)) {
    throw new RuntimeException('Invalid concurrent MFA test input.');
}
$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
if (!$container instanceof ContainerInterface) {
    throw new RuntimeException('Missing test container.');
}
$em = $container->get(EntityManagerInterface::class);
if (!$em instanceof EntityManagerInterface || $em->getConnection()->getDatabase() !== $value['database']) {
    throw new RuntimeException('Concurrent MFA test must use the same test database.');
}
$em->getConnection()->executeStatement("SELECT set_config('application_name', :name, false)", ['name' => $value['applicationName']]);
$repository = $container->get(AdminMfaRepository::class);
if (!$repository instanceof AdminMfaRepository) {
    throw new RuntimeException('Missing MFA repository.');
}
$result = $repository->verify(Uuid::fromString($value['administratorId']), $value['version'], $value['code'], new DateTimeImmutable('@1792929600'));
fwrite(\STDOUT, json_encode(['verified' => null !== $result], \JSON_THROW_ON_ERROR));
