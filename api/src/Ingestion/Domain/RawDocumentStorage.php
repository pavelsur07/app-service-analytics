<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Хранилище тел сырых документов (ADR-024). Метаданные — строка
 * marketplace_raw_document в PostgreSQL; тело — объект по RawObjectKey.
 *
 * Интерфейс в Domain ради границы Deptrac: Domain и Application не видят
 * клиента S3, он живёт только в Infrastructure (docs/patterns.md,
 * «Когда интерфейс в Domain нужен»).
 */
interface RawDocumentStorage
{
    /**
     * Точные байты ответа. Объект пишется, только если его ещё нет:
     * ключ детерминирован содержимым, поэтому повторная запись того же
     * ключа — уже выполненная запись, а не новая версия объекта.
     */
    public function put(RawObjectKey $key, string $body): void;

    /**
     * @throws RawObjectNotFound объекта нет — не пустая строка и не null:
     *                           пропавшее сырьё не должно выглядеть пустым ответом
     */
    public function get(RawObjectKey $key): string;

    public function exists(RawObjectKey $key): bool;
}
