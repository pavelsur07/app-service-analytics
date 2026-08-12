<?php

declare(strict_types=1);

namespace App\Identity\Domain\ValueObject;

/**
 * Секрет токена расширения браузера (ADR-010). Три производных от одного
 * случайного значения — открытый текст, его хэш и отображаемый префикс —
 * держатся вместе, чтобы не разъехались: в базу уходит только хэш,
 * открытый текст показывается клиенту один раз при выпуске.
 *
 * Хэш, а не шифротекст: токен проверяется сравнением, а не читается —
 * значит мастер-ключ здесь не нужен, в отличие от учётных данных площадок
 * (ADR-007, CredentialsCipher). Дамп базы не даёт действующих токенов.
 *
 * Опознаваемый префикс — чтобы утёкшая строка распознавалась в логах
 * и сканерами секретов, а не выглядела случайным набором символов.
 */
final readonly class ExtensionTokenSecret
{
    public const string PREFIX = 'conwix_ext_';

    /**
     * 32 байта — 256 бит энтропии: перебор невозможен, поэтому
     * ограничение попыток на этом эндпоинте не нужно (в отличие от входа
     * по паролю, ADR-007).
     */
    private const int RANDOM_BYTES = 32;

    /**
     * Сколько символов случайной части попадает в отображаемый префикс.
     * Восемь символов base64url — 48 бит; остаток в 208 бит перебор
     * по-прежнему не берёт, а отличить два токена в списке уже можно.
     */
    private const int DISPLAY_RANDOM_CHARS = 8;

    private function __construct(
        private string $plaintext,
    ) {
    }

    public static function generate(): self
    {
        return new self(self::PREFIX.self::base64Url(random_bytes(self::RANDOM_BYTES)));
    }

    /**
     * Для проверяющей стороны: она видит только предъявленную строку
     * и не строит VO — сравнивать всё равно с колонкой хэша.
     */
    public static function hashOf(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * Показывается клиенту один раз, при выпуске. Повторно не выдаётся —
     * в базе его нет.
     */
    public function plaintext(): string
    {
        return $this->plaintext;
    }

    public function hash(): string
    {
        return self::hashOf($this->plaintext);
    }

    public function displayPrefix(): string
    {
        return substr($this->plaintext, 0, \strlen(self::PREFIX) + self::DISPLAY_RANDOM_CHARS);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
