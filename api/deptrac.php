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
                    mustNot: [
                        ClassLikeConfig::create('^App\\Identity\\Infrastructure\\Query\\ActiveOzonAccountsQuery$'),
                        ClassLikeConfig::create('^App\\Identity\\Infrastructure\\Query\\ExtensionTokenByHashQuery$'),
                        ClassLikeConfig::create('^App\\Identity\\Infrastructure\\Security\\ExtensionTokenHandler$'),
                    ],
                ),
            ),
            $identityOperationalQuery = Layer::withName('IdentityOperationalQuery')->collectors(
                ClassLikeConfig::create('^App\\Identity\\Infrastructure\\Query\\ActiveOzonAccountsQuery$'),
            ),
            // Поиск токена расширения по хэшу — межарендаторный: компания
            // ещё неизвестна, её как раз и определяет найденная строка
            // (ADR-010). Тем же приёмом, что ActiveOzonAccountsQuery выше,
            // выведен из широкого IdentityInfrastructure — иначе доступ
            // к нему получил бы и IdentityUi (у которого этот доступ есть
            // ради MeController → UserCompaniesQuery), то есть HTTP-контроллер
            // смог бы искать токен без companyId, вопреки CLAUDE.md §1.
            $identityExtensionTokenQuery = Layer::withName('IdentityExtensionTokenQuery')->collectors(
                ClassLikeConfig::create('^App\\Identity\\Infrastructure\\Query\\ExtensionTokenByHashQuery$'),
            ),
            // Узкий слой на втором конце того же вызова: единственный, кому
            // разрешён запрос выше. Держать обработчик в широком слое
            // бессмысленно — грант пришлось бы выдавать всему
            // IdentityInfrastructure, и граница исчезла бы.
            $identityExtensionTokenHandler = Layer::withName('IdentityExtensionTokenHandler')->collectors(
                ClassLikeConfig::create('^App\\Identity\\Infrastructure\\Security\\ExtensionTokenHandler$'),
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
                        ClassLikeConfig::create('^App\\Ingestion\\Application\\NotifyStaleAccountsAction$'),
                    ],
                ),
            ),
            // Два узких слоя, а не один на оба Action: RecentlyIngestedAccountsQuery
            // нужен только сторожу свежести, и общий слой выдал бы межарендаторное
            // чтение заодно планировщику, которому оно не нужно. CLAUDE.md §1
            // требует давать узкий слой только тому, кому он действительно нужен.
            $ingestionSyncAction = Layer::withName('IngestionSyncAction')->collectors(
                ClassLikeConfig::create('^App\\Ingestion\\Application\\DispatchActiveOzonSyncsAction$'),
            ),
            $ingestionFreshnessAction = Layer::withName('IngestionFreshnessAction')->collectors(
                ClassLikeConfig::create('^App\\Ingestion\\Application\\NotifyStaleAccountsAction$'),
            ),
            $ingestionFacade = Layer::withName('IngestionFacade')->collectors(
                DirectoryConfig::create('src/Ingestion/Application/Facade/.*'),
            ),
            // RecentlyIngestedAccountsQuery вынесен из IngestionInfrastructure
            // тем же приёмом, что ActiveOzonAccountsQuery из IdentityInfrastructure:
            // IngestionUi имеет широкий доступ к IngestionInfrastructure (см. ниже),
            // а этот запрос читает подключения всех компаний сразу — без mustNot
            // любой контроллер Ingestion мог бы внедрить его в обход CLAUDE.md §1.
            $ingestionInfrastructure = Layer::withName('IngestionInfrastructure')->collectors(
                BoolConfig::create(
                    must: [DirectoryConfig::create('src/Ingestion/Infrastructure/.*')],
                    mustNot: [ClassLikeConfig::create('^App\\Ingestion\\Infrastructure\\Query\\RecentlyIngestedAccountsQuery$')],
                ),
            ),
            $ingestionOperationalQuery = Layer::withName('IngestionOperationalQuery')->collectors(
                ClassLikeConfig::create('^App\\Ingestion\\Infrastructure\\Query\\RecentlyIngestedAccountsQuery$'),
            ),
            // Зеркально: единственные классы IngestionUi, которым нужен
            // (и разрешён) доступ к IngestionOperationalAction — команды
            // фоновых процессов. Остальной IngestionUi (ListSalesFactsController
            // и будущие контроллеры) их не видит.
            $ingestionOperationalCommand = Layer::withName('IngestionOperationalCommand')->collectors(
                ClassLikeConfig::create('^App\\Ingestion\\Ui\\Command\\(ScheduleOzonSync|CheckDataFreshness)Command$'),
            ),
            $ingestionUi = Layer::withName('IngestionUi')->collectors(
                BoolConfig::create(
                    must: [DirectoryConfig::create('src/Ingestion/Ui/.*')],
                    mustNot: [ClassLikeConfig::create('^App\\Ingestion\\Ui\\Command\\(ScheduleOzonSync|CheckDataFreshness)Command$')],
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
            // identityExtensionTokenQuery — только обработчику токена, никому
            // больше; identityInfrastructure — ради ExtensionTokenRow
            // и ExtensionTokenRequestAttributes (плоские классы широкого слоя,
            // не способности). Тот же состав грантов, что у identityScheduleFacade:
            // узкий слой на узкий плюс широкий слой ради DTO.
            Ruleset::forLayer($identityExtensionTokenHandler)->accesses($identityExtensionTokenQuery, $identityDomain, $identityInfrastructure, $symfonyComponent),
            Ruleset::forLayer($identityExtensionTokenQuery)->accesses($identityInfrastructure),
            Ruleset::forLayer($identityInfrastructure)->accesses($identityDomain, $sharedApplication, $sharedDomain, $sharedInfrastructure, $symfonyComponent, $symfonyUid, $symfonySecurityUser),
            Ruleset::forLayer($identityDomain)->accesses($sharedDomain, $symfonyUid, $symfonySecurityUser),

            // Ingestion — вход в Identity только через IdentityFacade;
            // Ui вообще не пересекает границу модуля, даже через Facade.
            // IngestionInfrastructure — только Query: чтение синхронное
            // и не требует оркестрации Application (CLAUDE.md: «Синхронные
            // сценарии вызываются напрямую из Ui»), в отличие от записи,
            // которая всегда идёт через Application/Facade.
            Ruleset::forLayer($ingestionUi)->accesses($ingestionApplication, $ingestionDomain, $ingestionInfrastructure, $sharedUi, $sharedApplication, $sharedDomain, $symfonyComponent, $symfonyUid, $nelmioApiDoc, $openApiAttributes),
            // Команды фоновых процессов — не весь IngestionUi: только им
            // разрешён IngestionOperationalAction (см. слой выше).
            Ruleset::forLayer($ingestionOperationalCommand)->accesses($ingestionSyncAction, $ingestionFreshnessAction, $sharedUi, $sharedApplication, $sharedDomain, $symfonyComponent),
            Ruleset::forLayer($ingestionApplication)->accesses($ingestionDomain, $identityFacade, $sharedApplication, $sharedDomain, $symfonyComponent, $symfonyUid),
            // identityScheduleFacade, не identityFacade: единственный
            // потребитель межарендаторного чтения, узкий слой на узкий
            // слой (см. комментарий у identityScheduleFacade выше).
            Ruleset::forLayer($ingestionSyncAction)->accesses($ingestionDomain, $ingestionApplication, $identityScheduleFacade, $sharedApplication, $sharedDomain, $symfonyComponent),
            // ingestionOperationalQuery — межарендаторное чтение свежести,
            // только этому слою и никому больше (тот же состав грантов,
            // что у identityScheduleFacade: узкий слой на узкий).
            Ruleset::forLayer($ingestionFreshnessAction)->accesses($ingestionDomain, $ingestionApplication, $ingestionOperationalQuery, $identityScheduleFacade, $sharedApplication, $sharedDomain, $symfonyComponent),
            Ruleset::forLayer($ingestionOperationalQuery)->accesses($ingestionInfrastructure, $sharedInfrastructure, $symfonyComponent),
            Ruleset::forLayer($ingestionFacade)->accesses($ingestionDomain, $ingestionApplication, $identityFacade, $sharedApplication, $sharedDomain),
            Ruleset::forLayer($ingestionInfrastructure)->accesses($ingestionDomain, $identityFacade, $sharedApplication, $sharedDomain, $sharedInfrastructure, $symfonyComponent, $symfonyUid),
            Ruleset::forLayer($ingestionDomain)->accesses($sharedDomain, $symfonyUid),
        )
    ;
};
