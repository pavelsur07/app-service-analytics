<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response;

final readonly class SelfRegistrationResponse
{
    public const string ACCEPTED_MESSAGE = 'Если адрес указан верно, письмо с дальнейшими инструкциями уже отправлено.';

    public function __construct(
        public string $message,
    ) {
    }
}
