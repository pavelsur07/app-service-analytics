import type { components } from '../../../api/schema'

type DataCoverageRow = components['schemas']['DataCoverageRowResponse']
export type CoverageStatus = DataCoverageRow['statuses'][number]

const TIMEZONE = 'Europe/Moscow'

const MONTHS_GENITIVE = [
  'января',
  'февраля',
  'марта',
  'апреля',
  'мая',
  'июня',
  'июля',
  'августа',
  'сентября',
  'октября',
  'ноября',
  'декабря',
] as const

/** Номер дня для заголовка колонки. */
export function dayNumber(day: string): string {
  return String(Number(day.slice(8, 10)))
}

export interface StatusView {
  label: string
  /** Квадрат ячейки: цвет и форма без текста, чтобы карта читалась. */
  cell: string
  /** Единственный знак — только у ошибки: форма, а не только цвет. */
  mark: string
}

export function statusView(status: CoverageStatus): StatusView {
  switch (status) {
    case 'loaded':
      return { label: 'загружено', cell: 'bg-positive-icon', mark: '' }
    case 'missing':
      return {
        label: 'нет данных',
        cell: 'border border-border-strong bg-surface-raised',
        mark: '',
      }
    case 'failed':
      return {
        label: 'ошибка загрузки',
        cell: 'bg-negative-icon text-text-inverse',
        mark: '×',
      }
    case 'pending':
      return { label: 'ещё рано', cell: 'bg-border-subtle', mark: '' }
  }
}

/**
 * Подсказка ячейки: день, эндпоинт, статус и, если загружено, когда
 * последний раз — по Москве.
 */
export function cellTitle(
  day: string,
  row: DataCoverageRow,
  status: CoverageStatus,
  lastReceivedAt: string | null,
): string {
  const month = MONTHS_GENITIVE[Number(day.slice(5, 7)) - 1] ?? ''
  const date = `${dayNumber(day)} ${month}`
  const what = row.endpoint === '' ? 'Итого' : row.endpoint
  const received =
    lastReceivedAt === null
      ? ''
      : ` · последняя загрузка ${new Intl.DateTimeFormat('ru-RU', {
          timeZone: TIMEZONE,
          day: '2-digit',
          month: '2-digit',
          hour: '2-digit',
          minute: '2-digit',
        }).format(new Date(lastReceivedAt))}`

  return `${date} · ${what} · ${statusView(status).label}${received}`
}
