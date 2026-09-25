// Что показать клиенту на отказ ввода рекламного ключа (ADR-026).
//
// Отдельная чистая функция по той же причине, что replaceCredentialsError:
// у каждого исхода своё следующее действие человека. Обязательное
// покрытие §10 — разбор ошибок API.
export interface ConnectAdvertisingFailure {
  title: string
  description: string
  // Список подключений устарел, и экран обязан перечитать его сам.
  refetch: boolean
}

const BY_CODE: Record<string, ConnectAdvertisingFailure> = {
  advertising_credentials_rejected: {
    title: 'Ozon не принял рекламный ключ',
    description:
      'Проверьте client_id и client_secret: кабинет продавца → Настройки → API-ключи → Performance API. Продажи и расходы это не затрагивает.',
    refetch: false,
  },
  advertising_credentials_of_another_cabinet: {
    title: 'Ключ от другого кабинета',
    description:
      'Товаров из рекламных кампаний этого ключа нет в каталоге подключённого магазина. Сохранять такой ключ нельзя: в отчёты попала бы реклама другого магазина.',
    refetch: false,
  },
  marketplace_unavailable: {
    title: 'Ozon сейчас не отвечает',
    description: 'Ключ выпускать не нужно. Повторите через несколько минут.',
    refetch: false,
  },
  connection_revoked: {
    title: 'Подключение отключено',
    description:
      'К отключённому подключению рекламный ключ не добавить. Напишите нам — подключим заново.',
    refetch: true,
  },
  version_conflict: {
    title: 'Данные успели измениться',
    description:
      'Подключение изменил кто-то ещё, пока была открыта форма. Мы обновили список — проверьте и повторите.',
    refetch: true,
  },
  connection_not_found: {
    title: 'Подключение не найдено',
    description: 'Возможно, его уже удалили. Обновите страницу.',
    refetch: true,
  },
}

export function connectAdvertisingFailure(
  code: string | null,
): ConnectAdvertisingFailure {
  if (code !== null && code in BY_CODE) {
    return BY_CODE[code] as ConnectAdvertisingFailure
  }

  // Незнакомый код или ответ без тела: неизвестно, дошёл ли запрос.
  return {
    title: 'Не удалось сохранить рекламный ключ',
    description: 'Повторите попытку. Если повторяется — напишите нам.',
    refetch: true,
  }
}
