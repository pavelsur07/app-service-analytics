// Что показать клиенту на отказ удаления подключения.
//
// Отдельный словарь, не расширение replaceCredentialsError.ts: у замены
// ключей и удаления разные действия, разные коды (connection_not_found,
// connection_has_history — своя пара, не пересекается с
// credentials_rejected/version_conflict) и разное следующее действие
// человека. Общий словарь заставил бы каждого читателя разбираться,
// какие коды относятся к какой форме. Обязательное покрытие §10 —
// разбор ошибок API.
export interface DiscardConnectionFailure {
  title: string
  description: string
  // Список подключений устарел и обязан перечитать себя сам — тот же
  // приём, что у replaceCredentialsFailure (§7).
  refetch: boolean
}

const BY_CODE: Record<string, DiscardConnectionFailure> = {
  connection_not_found: {
    title: 'Подключение уже удалено',
    description:
      'Его удалили раньше — возможно, из другой вкладки. Список обновлён.',
    refetch: true,
  },
  connection_has_history: {
    title: 'У подключения есть загруженные данные',
    description:
      'Удалить нельзя — история бы потерялась. Если ключ выпущен не от того кабинета, замените его в этом же подключении: удаление предназначено только для кабинетов, которые ничего не успели загрузить.',
    // Ничего не изменилось: строка на месте, состояние то же самое.
    // Перечитывать список незачем — только сбросил бы открытую карточку.
    refetch: false,
  },
}

export function discardConnectionFailure(
  code: string | null,
): DiscardConnectionFailure {
  if (code !== null && code in BY_CODE) {
    return BY_CODE[code] as DiscardConnectionFailure
  }

  // Незнакомый код или ответ без тела (сеть упала, прокси отдал HTML).
  // Неизвестно, дошло ли удаление, — безопаснее перечитать список,
  // чем утверждать, что подключение осталось на месте.
  return {
    title: 'Не удалось удалить подключение',
    description: 'Повторите попытку. Если повторяется — напишите нам.',
    refetch: true,
  }
}
