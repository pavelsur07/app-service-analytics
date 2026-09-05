// Что показать клиенту на отказ подключения (ADR-021).
//
// Отдельная чистая функция, а не ветвление в компоненте: у каждого
// исхода своё следующее действие человека.
export interface ConnectAccountFailure {
  title: string
  description: string
}

const BY_CODE: Record<string, ConnectAccountFailure> = {
  credentials_rejected: {
    title: 'Площадка не приняла ключ',
    description:
      'Проверьте, что Client-Id и Api-Key скопированы целиком и выпущены в одном кабинете. Подключение не создано.',
  },
  credentials_rejected_sales: {
    title: 'Ключу не хватает права на продажи',
    description:
      'Включите доступ к отправлениям (FBO/FBS) в кабинете продавца и выпустите ключ заново. Подключение не создано.',
  },
  credentials_rejected_expenses: {
    title: 'Ключу не хватает права на финансы',
    description:
      'Включите доступ к финансовым отчётам в кабинете продавца и выпустите ключ заново. Подключение не создано.',
  },
  credentials_rejected_returns: {
    title: 'Ключу не хватает права на возвраты',
    description:
      'Включите доступ к возвратам в кабинете продавца и выпустите ключ заново. Подключение не создано.',
  },
  cabinet_already_connected: {
    title: 'Кабинет уже подключён',
    description:
      'Один кабинет Ozon подключается только к одному аккаунту. Если кабинет ваш, напишите нам — разберёмся.',
  },
  marketplace_unavailable: {
    title: 'Ozon сейчас не отвечает',
    description:
      'Ключ выпускать не нужно — с ключами всё в порядке. Повторите через несколько минут.',
  },
  name_required: {
    title: 'Укажите название магазина',
    description:
      'Оно нужно, чтобы отличать кабинеты: Client-Id из цифр ни о чём не говорит.',
  },
  client_id_required: {
    title: 'Укажите Client-Id',
    description: 'Client-Id есть в кабинете продавца, в разделе с API-ключами.',
  },
  api_key_required: {
    title: 'Укажите Api-Key',
    description: 'Api-Key выпускается в кабинете продавца рядом с Client-Id.',
  },
}

export function connectAccountFailure(
  code: string | null,
): ConnectAccountFailure {
  if (code !== null && code in BY_CODE) {
    return BY_CODE[code] as ConnectAccountFailure
  }

  return {
    title: 'Не удалось подключить кабинет',
    description: 'Повторите попытку. Если повторяется — напишите нам.',
  }
}
