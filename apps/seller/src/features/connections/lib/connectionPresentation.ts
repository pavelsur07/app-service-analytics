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
