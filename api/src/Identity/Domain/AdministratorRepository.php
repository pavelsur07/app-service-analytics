<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * findByEmail без companyId первым параметром — исключение
 * «аутентификационная граница» из CLAUDE.md §1, и оба его условия
 * выполнены: у Administrator companyId нет структурно (администратор
 * не принадлежит арендатору), а результат чтения — сам предъявитель
 * учётных данных. Та же природа, что у UserRepository::findByEmail:
 * проверить пароль, не найдя сначала строку по email, физически нельзя.
 *
 * Метод репозитория, а не отдельный DBAL-запрос, ровно по критерию
 * того же пункта: отдельный запрос нужен там, где сущность компанию
 * имеет и с неё пришлось бы снимать скоуп. Здесь снимать нечего.
 */
interface AdministratorRepository
{
    public function add(Administrator $administrator): void;

    public function findByEmail(string $email): ?Administrator;
}
