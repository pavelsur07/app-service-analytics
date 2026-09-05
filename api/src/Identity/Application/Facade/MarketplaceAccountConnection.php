<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

/**
 * Исход подключения вместе с идентификатором созданной строки.
 *
 * Идентификатор возвращается сразу, а не читается следом: ответ 201
 * обязан назвать созданный ресурс, и перечитывать подключения компании
 * ради строки, которую мы только что записали, — лишний запрос
 * и лишняя возможность разойтись с самим собой.
 */
final readonly class MarketplaceAccountConnection
{
    private function __construct(
        public MarketplaceAccountConnectionOutcome $outcome,
        /** Заполнен только у Connected. */
        public ?string $accountId,
    ) {
    }

    public static function connected(string $accountId): self
    {
        return new self(MarketplaceAccountConnectionOutcome::Connected, $accountId);
    }

    public static function alreadyConnected(): self
    {
        return new self(MarketplaceAccountConnectionOutcome::AlreadyConnected, null);
    }
}
