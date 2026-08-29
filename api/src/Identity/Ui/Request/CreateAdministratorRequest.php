<?php

declare(strict_types=1);

namespace App\Identity\Ui\Request;

/**
 * DTO запроса, не Symfony Form (docs/patterns.md, «HTTP-слой»).
 *
 * Роли в теле нет намеренно: форма заводит только `Admin`, верхняя роль
 * недостижима из HTTP ни при каких значениях полей (ADR-017). Поле,
 * которого нет, нельзя ни забыть провалидировать, ни подделать.
 *
 * Проверки здесь — граница доверия: тело приходит от клиента, и больше
 * проверить его негде.
 */
final readonly class CreateAdministratorRequest
{
    /**
     * Нижняя граница длины пароля. Администратор видит финансы всех
     * клиентов, а второго фактора пока нет (ADR-017, отступление
     * от ADR-007) — до его появления длина остаётся единственным,
     * что стоит между перебором и всеми данными сразу.
     */
    public const int MIN_PASSWORD_LENGTH = 12;

    private function __construct(
        public string $email,
        public string $password,
    ) {
    }

    /**
     * @throws \InvalidArgumentException с кодом ошибки для ответа 422
     */
    public static function fromJson(string $body): self
    {
        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('malformed_json');
        }

        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('malformed_json');
        }

        $email = $decoded['email'] ?? null;
        if (!\is_string($email) || false === filter_var(trim($email), \FILTER_VALIDATE_EMAIL) || mb_strlen(trim($email)) > 255) {
            throw new \InvalidArgumentException('email_invalid');
        }

        $password = $decoded['password'] ?? null;
        if (!\is_string($password) || mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException('password_too_short');
        }

        return new self(trim($email), $password);
    }
}
