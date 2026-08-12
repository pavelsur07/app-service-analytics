// Статус читается без цвета (docs/patterns.md, «Данные и статусы»): тон —
// усиление, подпись и Lucide-иконка — носители смысла. Ozon может
// прислать статус, которого здесь нет (список площадки шире, ADR-009) —
// это не повод падать, просто нейтральный тон и сырой текст статуса.
type StatusTone = 'positive' | 'negative' | 'warning' | 'neutral'

export interface StatusPresentation {
  tone: StatusTone
  label: string
}

const KNOWN_STATUSES: Record<string, StatusPresentation> = {
  delivered: { tone: 'positive', label: 'Доставлено' },
  cancelled: { tone: 'negative', label: 'Отменено' },
  awaiting_packaging: { tone: 'warning', label: 'Ожидает сборки' },
  awaiting_deliver: { tone: 'warning', label: 'Передаётся в доставку' },
}

export function statusPresentation(status: string): StatusPresentation {
  return KNOWN_STATUSES[status] ?? { tone: 'neutral', label: status }
}
