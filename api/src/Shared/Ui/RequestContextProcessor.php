<?php

declare(strict_types=1);

namespace App\Shared\Ui;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Добавляет `request_id` и `company_id` в каждую запись журнала.
 *
 * CLAUDE.md, «Наблюдаемость»: «В логах — идентификатор запроса
 * и company_id. Обращение вида "не сходится за 15 июля" должно
 * превращаться в один запрос по логам». Процессором, а не аргументом
 * каждого вызова: поле, которое надо не забыть, однажды забудут —
 * и именно в той строке, ради которой журнал читают.
 *
 * Живёт в `Ui`, а не рядом с SentryEventScrubber в `Infrastructure`:
 * читает HTTP-запрос, а запрос — территория Ui. Обратное направление
 * (Infrastructure тянет константу из Ui) Deptrac запрещает, и правильно:
 * зависимости строго вниз.
 *
 * `company_id` берётся из атрибутов маршрута, а не из сессии или
 * контекста безопасности: компания передаётся явно и живёт в пути
 * (CLAUDE.md §1), и брать её откуда-то ещё значило бы завести второй
 * источник истины о том, чьи это данные.
 *
 * **Вне HTTP-запроса обоих полей нет, и это известный пробел, а не
 * решённый случай.** В обработчике очереди, у планировщика и у сторожа
 * свежести компания известна — она лежит в самом сообщении, — но сюда
 * не доходит: процессор умеет читать только запрос. Значит, отказ
 * синхронизации в журнале не связать с компанией, а это как раз тот
 * случай, ради которого журнал читают.
 *
 * Не закрыто здесь потому, что закрывается не процессором: нужен
 * middleware очереди, кладущий companyId сообщения в контекст, и общий
 * признак «сообщение знает компанию» у самих сообщений. Сегодня
 * ни один воркер в журнал не пишет вовсе, так что пробел пока
 * теоретический — но он станет настоящим в тот день, когда там
 * появится первая запись.
 *
 * Подставлять «unknown» вместо отсутствующего поля незачем: пустой ключ
 * в JSON-строке читается так же однозначно, а выдуманное значение
 * однажды примут за настоящее.
 */
final readonly class RequestContextProcessor implements ProcessorInterface
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return $record;
        }

        $extra = $record->extra;

        $requestId = $request->attributes->get(RequestAttributes::RequestId);
        if (\is_string($requestId)) {
            $extra['request_id'] = $requestId;
        }

        $companyId = $request->attributes->get('companyId');
        if (\is_string($companyId)) {
            $extra['company_id'] = $companyId;
        }

        return $record->with(extra: $extra);
    }
}
