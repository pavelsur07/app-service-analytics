<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Observability;

use App\Shared\Ui\RequestAttributes;
use App\Shared\Ui\RequestContextProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Обещание CLAUDE.md, раздел «Наблюдаемость»: «В логах — идентификатор
 * запроса и company_id. Обращение вида "не сходится за 15 июля" должно
 * превращаться в один запрос по логам». Без этих полей журнал есть,
 * а обещания нет — и заметить это можно только тогда, когда журнал
 * понадобится.
 */
final class RequestContextProcessorTest extends TestCase
{
    public function testAddsRequestIdAndCompanyIdFromTheCurrentRequest(): void
    {
        $request = new Request();
        $request->attributes->set(RequestAttributes::RequestId, '01a01104-8634-7bee-9c85-8f524802c241');
        $request->attributes->set('companyId', '01a01104-8634-7bee-9c85-8f524802c241');

        $record = ($this->processorFor($request))($this->record());

        self::assertSame('01a01104-8634-7bee-9c85-8f524802c241', $record->extra['request_id']);
        self::assertSame('01a01104-8634-7bee-9c85-8f524802c241', $record->extra['company_id']);
    }

    public function testCompanyIdIsAbsentOnRoutesWithoutIt(): void
    {
        // Маршруты входа и /api/extension/me компании в пути не имеют.
        // Подставлять «unknown» незачем: отсутствие ключа читается так же
        // однозначно, а выдуманное значение однажды примут за настоящее.
        $request = new Request();
        $request->attributes->set(RequestAttributes::RequestId, '01a01104-8634-7bee-9c85-8f524802c241');

        $record = ($this->processorFor($request))($this->record());

        self::assertSame('01a01104-8634-7bee-9c85-8f524802c241', $record->extra['request_id']);
        self::assertArrayNotHasKey('company_id', $record->extra);
    }

    public function testOutsideAnHttpRequestTheRecordIsUntouched(): void
    {
        // Консольная команда и обработчик очереди: запроса нет вовсе,
        // и процессор обязан не падать, а пройти мимо.
        $processor = new RequestContextProcessor(new RequestStack());

        $record = $processor($this->record());

        self::assertSame([], $record->extra);
    }

    private function processorFor(Request $request): RequestContextProcessor
    {
        $stack = new RequestStack();
        $stack->push($request);

        return new RequestContextProcessor($stack);
    }

    private function record(): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable('2026-08-18 09:00:00'),
            channel: 'app',
            level: Level::Warning,
            message: 'что-то произошло',
        );
    }
}
