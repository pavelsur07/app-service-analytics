<?php

declare(strict_types=1);

use Deptrac\Deptrac\Contract\Config\Collector\BoolConfig;
use Deptrac\Deptrac\Contract\Config\Collector\ClassLikeConfig;
use Deptrac\Deptrac\Contract\Config\Collector\DirectoryConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

return static function (DeptracConfig $config): void {
    $config
        ->paths('./src')
        // Кэш — в var/ рядом с кэшами остальных инструментов (phpunit,
        // phpstan, php-cs-fixer), а не в корне api/.
        ->cacheFile('var/deptrac.cache')
        ->layers(
            $sharedDomain = Layer::withName('SharedDomain')->collectors(
                DirectoryConfig::create('src/Shared/Domain/.*'),
            ),
            $sharedApplication = Layer::withName('SharedApplication')->collectors(
                DirectoryConfig::create('src/Shared/Application/.*'),
            ),
            $sharedInfrastructure = Layer::withName('SharedInfrastructure')->collectors(
                DirectoryConfig::create('src/Shared/Infrastructure/.*'),
            ),
            $sharedUi = Layer::withName('SharedUi')->collectors(
                DirectoryConfig::create('src/Shared/Ui/.*'),
            ),

            $identityDomain = Layer::withName('IdentityDomain')->collectors(
                DirectoryConfig::create('src/Identity/Domain/.*'),
            ),
            $identityApplication = Layer::withName('IdentityApplication')->collectors(
                BoolConfig::create(
                    must: [DirectoryConfig::create('src/Identity/Application/.*')],
                    mustNot: [DirectoryConfig::create('src/Identity/Application/Facade/.*')],
                ),
            ),
            // IdentityScheduleFacade вынесен из IdentityFacade в отдельный
            // класс и отдельный слой: Deptrac различает только классы,
            // не методы одного класса, поэтому единственный способ дать
            // widely-доступному IdentityFacade безопасный метод
            // (findOzonSyncTarget, company-scoped) и при этом закрыть
            // межарендаторное чтение (findActiveOzonSyncTargets) от всех,
            // кроме одного вызывающего, — держать их на разных классах.
            $identityFacade = Layer::withName('IdentityFacade')->collectors(
                BoolConfig::create(
                    must: [DirectoryConfig::create('src/Identity/Application/Facade/.*')],
                    mustNot: [ClassLikeConfig::create('^App\\Identity\\Application\\Facade\\IdentityScheduleFacade$')],
                ),
            ),
            $identityScheduleFacade = Layer::withName('IdentityScheduleFacade')->collectors(
                ClassLikeConfig::create('^App\\Identity\\Application\\Facade\\IdentityScheduleFacade$'),
            ),
            // ActiveOzonAccountsQuery вынесен из IdentityInfrastructure тем же
            // приёмом: IdentityUi уже имеет широкий доступ к IdentityInfrastructure
            // (MeController → UserCompaniesQuery) — без mustNot этот класс
            // попал бы туда же, и HTTP-контроллер получил бы физическую
            // возможность выполнить межарендаторный запрос в обход CLAUDE.md §1.
            $identityInfrastructure = Layer::withName('IdentityInfrastructure')->collectors(
                BoolConfig::create(
                    must: [DirectoryConfig::create('src/Identity/Infrastructure/.*')],
                    mustNot: [ClassLikeConfig::create('^App\\Identity\\Infrastructure\\Query\\ActiveOzonAccountsQuery$')],
                ),
            ),
            $identityOperationalQuery = Layer::withName('IdentityOperationalQuery')->collectors(
                ClassLikeConfig::create('^App\\Identity\\Infrastructure\\Query\\ActiveOzonAccountsQuery$'),
            ),
            $identityUi = Layer::withName('IdentityUi')->collectors(
                DirectoryConfig::create('src/Identity/Ui/.*'),
            ),

            $ingestionDomain = Layer::withName('IngestionDomain')->collectors(
                DirectoryConfig::create('src/Ingestion/Domain/.*'),
            ),
            // DispatchActiveOzonSyncsAction вынесен из IngestionApplication
            // тем же приёмом, что ActiveOzonAccountsQuery из IdentityInfrastructure
            // выше: IngestionUi уже имеет широкий доступ к IngestionApplication
            // (см. ниже), и этот Action вызывает межарендаторный
            // IdentityFacade::findActiveOzonSyncTargets() — без mustNot
            // любой будущий HTTP-контроллер Ingestion (ListSalesFactsController
            // и подобные) мог бы внедрить его в обход CLAUDE.md §1, несмотря
            // на то что реальный вызывающий — только ScheduleOzonSyncCommand.
            $ingestionApplication = Layer::withName('IngestionApplication')->collectors(
                BoolConfig::create(
                    must: [DirectoryConfig::create('src/Ingestion/Application/.*')],
                    mustNot: [
                        DirectoryConfig::create('src/Ingestion/Application/Facade/.*'),
                        ClassLikeConfig::create('^App\\Ingestion\\Application\\DispatchActiveOzonSyncsAction$'),
                    ],
                ),
            ),
            $ingestionOperationalAction = Layer::withName('IngestionOperationalAction')->collectors(
                ClassLikeConfig::create('^App\\Ingestion\\Application\\DispatchActiveOzonSyncsAction$'),
            ),
            $ingestionFacade = Layer::withName('IngestionFacade')->collectors(
                DirectoryConfig::create('src/Ingestion/Application/Facade/.*'),
            ),
            $ingestionInfrastructure = Layer::withName('IngestionInfrastructure')->collectors(
                DirectoryConfig::create('src/Ingestion/Infrastructure/.*'),
            ),
            // ScheduleOzonSyncCommand — зеркально: единственный класс IngestionUi,
            // которому нужен (и разрешён) доступ к IngestionOperationalAction;
            // остальной IngestionUi (ListSalesFactsController и будущие
            // контроллеры) его не видит.
            $ingestionScheduleCommand = Layer::withName('IngestionScheduleCommand')->collectors(
                ClassLikeConfig::create('^App\\Ingestion\\Ui\\Command\\ScheduleOzonSyncCommand$'),
            ),
            $ingestionUi = Layer::withName('IngestionUi')->collectors(
                BoolConfig::create(
                    must: [DirectoryConfig::create('src/Ingestion/Ui/.*')],
                    mustNot: [ClassLikeConfig::create('^App\\Ingestion\\Ui\\Command\\ScheduleOzonSyncCommand$')],
                ),
            ),

            // Внешние библиотеки — не наши модули, но зависимость на них
            // реальна и должна быть покрыта правилом, а не висеть Uncovered.
            $brickMoney = Layer::withName('BrickMoney')->collectors(
                ClassLikeConfig::create('^Brick\\Money\\.*'),
            ),
            // symfony/uid, ADR-003: UUIDv7 для всех сущностей проекта.
            // Идентификатор — часть Domain (тип поля Entity), поэтому
            // выделен отдельным, более узким слоем, чем SymfonyComponent
            // в целом: Domain не должен видеть Symfony шире, чем это нужно.
            // Вынесен из SymfonyComponent через mustNot — иначе Uuid попал
            // бы сразу в оба слоя, и грант на узкий слой не снимал бы
            // нарушение по широкому.
            $symfonyUid = Layer::withName('SymfonyUid')->collectors(
                ClassLikeConfig::create('^Symfony\\Component\\Uid\\.*'),
            ),
            // Тот же принцип, что у SymfonyUid, и по той же причине: User —
            // сущность Domain, но Symfony Security требует, чтобы именно она
            // реализовывала UserInterface/PasswordAuthenticatedUserInterface
            // напрямую (это контракт аутентификации, не опциональная
            // обёртка) — обходной адаптер в Infrastructure добавил бы слой
            // косвенности ради одного класса, не ради границы.
            $symfonySecurityUser = Layer::withName('SymfonySecurityUser')->collectors(
                ClassLikeConfig::create('^Symfony\\Component\\Security\\Core\\User\\.*'),
            ),
            $symfonyComponent = Layer::withName('SymfonyComponent')->collectors(
                BoolConfig::create(
                    must: [ClassLikeConfig::create('^Symfony\\Component\\.*')],
                    mustNot: [
                        ClassLikeConfig::create('^Symfony\\Component\\Uid\\.*'),
                        ClassLikeConfig::create('^Symfony\\Component\\Security\\Core\\User\\.*'),
                    ],
                ),
            ),
            $nelmioApiDoc = Layer::withName('NelmioApiDoc')->collectors(
                ClassLikeConfig::create('^Nelmio\\ApiDocBundle\\.*'),
            ),
            $openApiAttributes = Layer::withName('OpenApiAttributes')->collectors(
                ClassLikeConfig::create('^OpenApi\\.*'),
            ),
            // sentry/sentry + sentry/sentry-symfony (SDK и бандл — один
            // общий неймспейс верхнего уровня, отдельный слой не нужен).
            $sentry = Layer::withName('Sentry')->collectors(
                ClassLikeConfig::create('^Sentry\\.*'),
            ),
        )
        ->rulesets(
            // Shared — технический слой, свободно используется всеми,
            // сам никогда не поднимается вверх к Identity/Ingestion.
            Ruleset::forLayer($sharedUi)->accesses($sharedApplication, $sharedDomain, $symfonyComponent, $nelmioApiDoc, $openApiAttributes),
            Ruleset::forLayer($sharedApplication)->accesses($sharedDomain),
            // Sentry — SentryEventScrubber (before_send, config/packages/sentry.yaml).
            Ruleset::forLayer($sharedInfrastructure)->accesses($sharedDomain, $sentry),
            Ruleset::forLayer($sharedDomain)->accesses($brickMoney),

            // Identity — ниже Ingestion, Shared доступен без ограничений,
            // в Ingestion не заходит вообще ни с одного слоя.
            //
            // IdentityUi отдаёт HTTP начиная с PR2 (MeController, обработчики
            // входа) — тот же набор прав, что уже есть у IngestionUi:
            // IdentityInfrastructure напрямую для синхронного чтения
            // (MeController → UserCompaniesQuery, тот же принцип, что
            // у ListSalesFactsController — см. комментарий в IngestionUi
            // ниже), SharedUi — переиспользование ValidationErrorResponse,
            // NelmioApiDoc/OpenApiAttributes — атрибуты на контроллере.
            // symfonyUid — идентификаторы существующих сущностей, принятые
            // как аргумент (companyId из ввода оператора, CreateUserCommand/
            // CreateUserWithMembershipAction), а не только сгенерированные
            // внутри Domain: тот же статус UID, что и у identityFacade ниже.
            Ruleset::forLayer($identityUi)->accesses($identityApplication, $identityDomain, $identityInfrastructure, $sharedUi, $sharedApplication, $sharedDomain, $symfonyComponent, $symfonyUid, $nelmioApiDoc, $openApiAttributes),
            Ruleset::forLayer($identityApplication)->accesses($identityDomain, $sharedApplication, $sharedDomain, $symfonyUid),
            Ruleset::forLayer($identityFacade)->accesses($identityDomain, $identityApplication, $sharedApplication, $sharedDomain, $symfonyUid),
            // identityOperationalQuery/identityInfrastructure (ради
            // ActiveOzonAccountRow) — только у IdentityScheduleFacade,
            // не у IdentityFacade выше: это и есть граница CLAUDE.md §1.
            // identityFacade тут же — ради OzonAccountRef (плоский DTO
            // в той же папке, не метод с побочным эффектом).
            Ruleset::forLayer($identityScheduleFacade)->accesses($identityFacade, $identityOperationalQuery, $identityInfrastructure, $sharedApplication, $sharedDomain),
            Ruleset::forLayer($identityOperationalQuery)->accesses($identityInfrastructure, $sharedInfrastructure, $symfonyComponent),
            Ruleset::forLayer($identityInfrastructure)->accesses($identityDomain, $sharedApplication, $sharedDomain, $sharedInfrastructure, $symfonyComponent, $symfonyUid, $symfonySecurityUser),
            Ruleset::forLayer($identityDomain)->accesses($sharedDomain, $symfonyUid, $symfonySecurityUser),

            // Ingestion — вход в Identity только через IdentityFacade;
            // Ui вообще не пересекает границу модуля, даже через Facade.
            // IngestionInfrastructure — только Query: чтение синхронное
            // и не требует оркестрации Application (CLAUDE.md: «Синхронные
            // сценарии вызываются напрямую из Ui»), в отличие от записи,
            // которая всегда идёт через Application/Facade.
            Ruleset::forLayer($ingestionUi)->accesses($ingestionApplication, $ingestionDomain, $ingestionInfrastructure, $sharedUi, $sharedApplication, $sharedDomain, $symfonyComponent, $symfonyUid, $nelmioApiDoc, $openApiAttributes),
            // ScheduleOzonSyncCommand — не весь IngestionUi: только этому
            // классу разрешён IngestionOperationalAction (см. слой выше).
            Ruleset::forLayer($ingestionScheduleCommand)->accesses($ingestionOperationalAction, $sharedUi, $sharedApplication, $sharedDomain, $symfonyComponent),
            Ruleset::forLayer($ingestionApplication)->accesses($ingestionDomain, $identityFacade, $sharedApplication, $sharedDomain, $symfonyComponent, $symfonyUid),
            // identityScheduleFacade, не identityFacade: единственный
            // потребитель межарендаторного чтения, узкий слой на узкий
            // слой (см. комментарий у identityScheduleFacade выше).
            Ruleset::forLayer($ingestionOperationalAction)->accesses($ingestionDomain, $ingestionApplication, $identityScheduleFacade, $sharedApplication, $sharedDomain, $symfonyComponent),
            Ruleset::forLayer($ingestionFacade)->accesses($ingestionDomain, $ingestionApplication, $identityFacade, $sharedApplication, $sharedDomain),
            Ruleset::forLayer($ingestionInfrastructure)->accesses($ingestionDomain, $identityFacade, $sharedApplication, $sharedDomain, $sharedInfrastructure, $symfonyComponent, $symfonyUid),
            Ruleset::forLayer($ingestionDomain)->accesses($sharedDomain, $symfonyUid),
        )
    ;
};
