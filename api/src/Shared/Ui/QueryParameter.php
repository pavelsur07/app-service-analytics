<?php

declare(strict_types=1);

namespace App\Shared\Ui;

use Symfony\Component\HttpFoundation\Request;

/**
 * Разбор числового параметра строки запроса.
 *
 * Существует потому, что `(int)` молча обрезает всё, что не число:
 * `days=30abc` превращается в 30, `limit=200.5` — в 200. Клиент при этом
 * получает не тот период и не тот размер страницы и узнаёт об этом
 * только по расхождению цифр. §5 требует отвечать 422 на некорректные
 * параметры, а не угадывать намерение.
 */
final class QueryParameter
{
    private function __construct()
    {
    }

    /**
     * Целое из строки запроса либо $default, если параметра нет.
     * null — параметр есть, но целым числом не является.
     */
    public static function int(Request $request, string $name, int $default): ?int
    {
        if (!$request->query->has($name)) {
            return $default;
        }

        $raw = $request->query->get($name);

        if (!\is_string($raw) || 1 !== preg_match('/^-?\d+$/', $raw)) {
            return null;
        }

        return (int) $raw;
    }
}
