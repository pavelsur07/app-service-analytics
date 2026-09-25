// Состояние подключения читается без цвета (docs/patterns.md, «Данные
// и статусы»): тон — усиление, подпись и объяснение — носители смысла.
//
// Подпись отвечает не «как называется состояние», а «что это значит
// для меня»: ADR-007 требует явной метки с указанием, что именно
// переподключить, и «broken» такой меткой не является.
type StateTone = 'positive' | 'negative' | 'warning' | 'neutral'

export interface ConnectionPresentation {
  tone: StateTone
  label: string
  explanation: string
}

const KNOWN_STATES: Record<string, ConnectionPresentation> = {
  active: {
    tone: 'positive',
    label: 'Работает',
    explanation: 'Данные загружаются по расписанию.',
  },
  broken: {
    tone: 'negative',
    label: 'Нужно переподключить',
    explanation:
      'Площадка отклонила ключ доступа: он отозван или перевыпущен в кабинете продавца. Загрузка остановлена, данные на месте. Выпустите новый ключ и напишите нам — заменим.',
  },
  revoked: {
    tone: 'neutral',
    label: 'Отключено',
    explanation:
      'Подключение отключено. История остаётся доступной, новые данные не загружаются.',
  },
}

export function connectionPresentation(state: string): ConnectionPresentation {
  return (
    KNOWN_STATES[state] ?? {
      tone: 'neutral',
      label: state,
      explanation: 'Состояние неизвестно приложению.',
    }
  )
}

// Реклама подключения (ADR-026) — своё состояние: отказ рекламного ключа
// ломает только рекламу, продажи и расходы продолжают грузиться. Подпись
// говорит именно это, иначе «нужно переподключить» прочиталось бы как
// поломка всего магазина.
const ADVERTISING_STATES: Record<string, ConnectionPresentation> = {
  active: {
    tone: 'positive',
    label: 'Реклама подключена',
    explanation: 'Статистика рекламных кампаний загружается по расписанию.',
  },
  broken: {
    tone: 'negative',
    label: 'Нужно заменить рекламный ключ',
    explanation:
      'Ozon отклонил рекламный ключ. Загрузка рекламы остановлена, продажи и расходы грузятся как прежде. Выпустите новый ключ Performance API и сохраните его здесь.',
  },
}

export function advertisingPresentation(
  state: string | null,
  connectionState: string,
): ConnectionPresentation {
  // Состояние подключения главное (ADR-026, п. 1): у сломанного
  // подключения реклама тоже не грузится, какой бы ключ у неё ни был.
  // «Реклама подключена» на таком экране была бы неправдой.
  if (state !== null && connectionState !== 'active') {
    return {
      tone: 'warning',
      label: 'Реклама остановлена',
      explanation:
        'Реклама не загружается, пока подключение магазина не работает. Восстановите подключение — реклама продолжит загружаться с тем же ключом.',
    }
  }

  if (state === null) {
    return {
      tone: 'neutral',
      label: 'Реклама не подключена',
      explanation:
        'Добавьте ключ Performance API, чтобы видеть расход по кампаниям и товарам.',
    }
  }

  return (
    ADVERTISING_STATES[state] ?? {
      tone: 'neutral',
      label: state,
      explanation: 'Состояние рекламы неизвестно приложению.',
    }
  )
}

// Что за выгрузка стоит за типом отчёта. Незнакомый тип показывается
// как есть: коннекторов будет больше, и падать из-за нового имени
// экран не должен.
const KNOWN_REPORTS: Record<string, string> = {
  ozon_posting_fbo_list: 'Продажи',
  ozon_product_list: 'Каталог',
}

export function reportLabel(reportType: string): string {
  return KNOWN_REPORTS[reportType] ?? reportType
}
