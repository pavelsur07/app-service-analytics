import type { components } from '../../../api/schema'

type DataCoverageRow = components['schemas']['DataCoverageRowResponse']
export type CoverageStatus = DataCoverageRow['statuses'][number]

const TIMEZONE = 'Europe/Moscow'

const MONTHS = [
  'январь',
  'февраль',
  'март',
  'апрель',
  'май',
  'июнь',
  'июль',
  'август',
  'сентябрь',
  'октябрь',
  'ноябрь',
  'декабрь',
] as const

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

/**
 * Текущий месяц по Москве, `YYYY-MM`: дни отчёта — дни площадки,
 * и полночь браузера восточнее Москвы не должна перелистывать месяц раньше.
 */
export function currentMonth(now: Date = new Date()): string {
  return new Intl.DateTimeFormat('sv-SE', {
    timeZone: TIMEZONE,
    year: 'numeric',
    month: '2-digit',
  }).format(now)
}

/** Месяц `YYYY-MM`, сдвинутый на `delta`. Неверная строка — текущий месяц. */
export function shiftMonth(month: string, delta: number): string {
  const match = /^(\d{4})-(\d{2})$/.exec(month)
  if (match === null) {
    return currentMonth()
  }
  const index = Number(match[1]) * 12 + (Number(match[2]) - 1) + delta
  const year = Math.floor(index / 12)
  const monthNumber = (index % 12) + 1

  return `${String(year)}-${String(monthNumber).padStart(2, '0')}`
}

/** «сентябрь 2026». */
export function monthLabel(month: string): string {
  const match = /^(\d{4})-(\d{2})$/.exec(month)
  const name = match === null ? undefined : MONTHS[Number(match[2]) - 1]

  return match === null || name === undefined ? month : `${name} ${match[1]}`
}

/** Следующего месяца отчёт не отдаёт: данных за будущее нет. */
export function canGoForward(month: string, current: string): boolean {
  return month < current
}

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
