// Что показать клиенту на отказ замены ключей.
//
// Отдельная чистая функция, а не ветвление в компоненте: у каждого
// исхода своё следующее действие человека, и перепутать их — значит
// отправить его выпускать новый ключ там, где надо было обновить
// страницу. Обязательное покрытие §10 — разбор ошибок API.
export interface ReplaceCredentialsFailure {
  title: string
  description: string
  // Список подключений устарел, и экран обязан перечитать его сам:
  // версия в форме уже не та, повторная отправка снова упрётся в конфликт.
  refetch: boolean
}

const BY_CODE: Record<string, ReplaceCredentialsFailure> = {
  credentials_rejected: {
    title: 'Площадка не приняла ключ',
    description:
      'Проверьте, что Api-Key скопирован целиком и выпущен в том же кабинете. Старый ключ остался на месте — синхронизация не изменилась.',
    refetch: false,
  },
  credentials_of_another_cabinet: {
    title: 'Ключ от другого кабинета',
    description:
      'Client-Id не совпадает с магазином, который подключён здесь. Ключ от чужого кабинета сохранять нельзя: в отчёты попали бы данные другого магазина.',
    refetch: false,
  },
  connection_revoked: {
    title: 'Подключение отключено',
    description:
      'Отключённое подключение заменой ключа не восстанавливается. Напишите нам — подключим заново.',
    refetch: true,
  },
  version_conflict: {
    title: 'Данные успели измениться',
    description:
      'Подключение изменил кто-то ещё, пока была открыта форма. Мы обновили список — проверьте состояние и повторите.',
    refetch: true,
  },
  connection_not_found: {
    title: 'Подключение не найдено',
    description: 'Возможно, его уже удалили. Обновите страницу.',
    refetch: true,
  },
}

export function replaceCredentialsFailure(
  code: string | null,
): ReplaceCredentialsFailure {
  if (code !== null && code in BY_CODE) {
    return BY_CODE[code] as ReplaceCredentialsFailure
  }

  // Незнакомый код или ответ без тела (упала сеть, прокси отдал HTML).
  // Обещать, что старый ключ на месте, здесь нельзя: неизвестно,
  // дошёл ли запрос.
  return {
    title: 'Не удалось заменить ключ',
    description: 'Повторите попытку. Если повторяется — напишите нам.',
    refetch: true,
  }
}
