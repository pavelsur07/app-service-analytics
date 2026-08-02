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
            $identityFacade = Layer::withName('IdentityFacade')->collectors(
                DirectoryConfig::create('src/Identity/Application/Facade/.*'),
            ),
            $identityInfrastructure = Layer::withName('IdentityInfrastructure')->collectors(
                DirectoryConfig::create('src/Identity/Infrastructure/.*'),
            ),
            $identityUi = Layer::withName('IdentityUi')->collectors(
                DirectoryConfig::create('src/Identity/Ui/.*'),
            ),

            $ingestionDomain = Layer::withName('IngestionDomain')->collectors(
                DirectoryConfig::create('src/Ingestion/Domain/.*'),
            ),
            $ingestionApplication = Layer::withName('IngestionApplication')->collectors(
                BoolConfig::create(
                    must: [DirectoryConfig::create('src/Ingestion/Application/.*')],
                    mustNot: [DirectoryConfig::create('src/Ingestion/Application/Facade/.*')],
                ),
            ),
            $ingestionFacade = Layer::withName('IngestionFacade')->collectors(
                DirectoryConfig::create('src/Ingestion/Application/Facade/.*'),
            ),
            $ingestionInfrastructure = Layer::withName('IngestionInfrastructure')->collectors(
                DirectoryConfig::create('src/Ingestion/Infrastructure/.*'),
            ),
            $ingestionUi = Layer::withName('IngestionUi')->collectors(
                DirectoryConfig::create('src/Ingestion/Ui/.*'),
            ),

            // Внешние библиотеки — не наши модули, но зависимость на них
            // реальна и должна быть покрыта правилом, а не висеть Uncovered.
            $brickMoney = Layer::withName('BrickMoney')->collectors(
                ClassLikeConfig::create('^Brick\\Money\\.*'),
            ),
            $symfonyComponent = Layer::withName('SymfonyComponent')->collectors(
                ClassLikeConfig::create('^Symfony\\Component\\.*'),
            ),
        )
        ->rulesets(
            // Shared — технический слой, свободно используется всеми,
            // сам никогда не поднимается вверх к Identity/Ingestion.
            Ruleset::forLayer($sharedUi)->accesses($sharedApplication, $sharedDomain, $symfonyComponent),
            Ruleset::forLayer($sharedApplication)->accesses($sharedDomain),
            Ruleset::forLayer($sharedInfrastructure)->accesses($sharedDomain),
            Ruleset::forLayer($sharedDomain)->accesses($brickMoney),

            // Identity — ниже Ingestion, Shared доступен без ограничений,
            // в Ingestion не заходит вообще ни с одного слоя.
            Ruleset::forLayer($identityUi)->accesses($identityApplication, $identityDomain, $sharedApplication, $sharedDomain),
            Ruleset::forLayer($identityApplication)->accesses($identityDomain, $sharedApplication, $sharedDomain),
            Ruleset::forLayer($identityFacade)->accesses($identityDomain, $identityApplication, $sharedApplication, $sharedDomain),
            Ruleset::forLayer($identityInfrastructure)->accesses($identityDomain, $sharedApplication, $sharedDomain, $sharedInfrastructure),
            Ruleset::forLayer($identityDomain)->accesses($sharedDomain),

            // Ingestion — вход в Identity только через IdentityFacade;
            // Ui вообще не пересекает границу модуля, даже через Facade.
            Ruleset::forLayer($ingestionUi)->accesses($ingestionApplication, $ingestionDomain, $sharedApplication, $sharedDomain),
            Ruleset::forLayer($ingestionApplication)->accesses($ingestionDomain, $identityFacade, $sharedApplication, $sharedDomain),
            Ruleset::forLayer($ingestionFacade)->accesses($ingestionDomain, $ingestionApplication, $identityFacade, $sharedApplication, $sharedDomain),
            Ruleset::forLayer($ingestionInfrastructure)->accesses($ingestionDomain, $identityFacade, $sharedApplication, $sharedDomain, $sharedInfrastructure),
            Ruleset::forLayer($ingestionDomain)->accesses($sharedDomain),
        )
    ;
};
