<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Строка метаданных есть, объекта нет. Это не пустой ответ площадки,
 * а потерянное сырьё, и разбор обязан упасть, а не продолжить с пустотой.
 */
final class RawObjectNotFound extends \RuntimeException
{
    public static function forKey(RawObjectKey $key, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('Объект сырья не найден: %s', $key->toString()), 0, $previous);
    }
}
