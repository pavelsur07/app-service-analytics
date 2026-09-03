<?php

declare(strict_types=1);

namespace App\Links\Infrastructure;

use App\Links\Domain\ShortCodeGenerator;

final class RandomShortCodeGenerator implements ShortCodeGenerator
{
    private const string ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function generate(): string
    {
        $code = '';

        while (7 > \strlen($code)) {
            $bytes = unpack('C*', random_bytes(7));
            if (false === $bytes) {
                throw new \RuntimeException('Unable to unpack random short-link bytes.');
            }

            foreach ($bytes as $byte) {
                // 248 — первое число после четырёх полных диапазонов
                // base62. Отброс хвоста 248..255 исключает modulo bias.
                if ($byte >= 248) {
                    continue;
                }

                $code .= self::ALPHABET[$byte % 62];
                if (7 === \strlen($code)) {
                    break;
                }
            }
        }

        return $code;
    }
}
