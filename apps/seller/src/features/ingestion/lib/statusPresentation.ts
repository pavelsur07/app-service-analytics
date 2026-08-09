// Статус читается без цвета (docs/patterns.md, «Данные и статусы»): тон —
// усиление, подпись со знаком — обязательный носитель смысла. Ozon может
// прислать статус, которого здесь нет (список площадки шире, ADR-009) —
// это не повод падать, просто нейтральный тон и сырой текст статуса.
type StatusTone = 'positive' | 'negative' | 'warning' | 'neutral'

export interface StatusPresentation {
  tone: StatusTone
  label: string
}

const KNOWN_STATUSES: Record<string, StatusPresentation> = {
  delivered: { tone: 'positive', label: '✓ доставлено' },
  cancelled: { tone: 'negative', label: '✕ отменено' },
  awaiting_packaging: { tone: 'warning', label: '⏳ ожидает сборки' },
  awaiting_deliver: { tone: 'warning', label: '⏳ передаётся в доставку' },
}

export function statusPresentation(status: string): StatusPresentation {
  return KNOWN_STATUSES[status] ?? { tone: 'neutral', label: status }
}
