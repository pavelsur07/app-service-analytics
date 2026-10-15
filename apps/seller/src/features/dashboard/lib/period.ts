// Период главной и период сравнения (docs/plan/dashboard-widgets.md).
//
// Даты — строки `YYYY-MM-DD` календаря площадки (Europe/Moscow, ADR-009),
// арифметика — над UTC-полночью: у UTC нет перевода часов, и «минус
// день» никогда не съедет на сутки из-за часового пояса браузера.
//
// Конец любого пресета — вчера: начисления за сегодня приходят
// неполными, и цифра выглядела бы рабочей, оставаясь неверной. Поэтому
// пресета «Сегодня», который есть в макете, здесь нет.

const TIMEZONE = 'Europe/Moscow'
const DAY_MS = 86_400_000

/** Ровно то же, что проверяет API: раньше этой даты период не начинается. */
export const EARLIEST_DATE = '2020-01-01'

/** Год с високосным днём — потолок длины периода у API. */
const MAX_PERIOD_DAYS = 366

export const PRESETS = [
  { id: 'yesterday', label: 'Вчера' },
  { id: '7d', label: '7 дней' },
  { id: '30d', label: '30 дней' },
  { id: 'month', label: 'Этот месяц' },
  { id: 'prevMonth', label: 'Прошлый месяц' },
  { id: 'quarter', label: 'Квартал' },
  { id: 'custom', label: 'Свой период' },
] as const

export type PresetId = (typeof PRESETS)[number]['id']

export const COMPARE_MODES = [
  { id: 'prev', label: 'Пред. период' },
  { id: 'year', label: 'Год назад' },
] as const

export type CompareMode = (typeof COMPARE_MODES)[number]['id']

export const DEFAULT_PRESET: PresetId = '7d'
export const DEFAULT_COMPARE: CompareMode = 'prev'

export interface DateRange {
  from: string
  to: string
}

export interface PeriodSelection {
  preset: PresetId
  compare: CompareMode
  /** Только у «Своего периода»; у пресетов диапазон выводится из сегодня. */
  custom: DateRange | null
}

const MONTHS = [
  'янв',
  'фев',
  'мар',
  'апр',
  'мая',
  'июн',
  'июл',
  'авг',
  'сен',
  'окт',
  'ноя',
  'дек',
] as const

function toTime(date: string): number {
  const [year, month, day] = date.split('-').map(Number)

  return Date.UTC(year ?? 1970, (month ?? 1) - 1, day ?? 1)
}

function fromTime(time: number): string {
  return new Date(time).toISOString().slice(0, 10)
}

export function addDays(date: string, days: number): string {
  return fromTime(toTime(date) + days * DAY_MS)
}

/** Сколько дней в периоде, включая оба конца. */
export function dayCount(range: DateRange): number {
  return Math.round((toTime(range.to) - toTime(range.from)) / DAY_MS) + 1
}

function parts(date: string): { year: number; month: number; day: number } {
  const [year, month, day] = date.split('-').map(Number)

  return { year: year ?? 1970, month: month ?? 1, day: day ?? 1 }
}

function ymd(year: number, month: number, day: number): string {
  // Date.UTC сам переносит месяц 0 и 13 через границу года, а день 0 —
  // в последний день предыдущего месяца.
  return fromTime(Date.UTC(year, month - 1, day))
}

/** Сегодня по календарю площадки, `YYYY-MM-DD`. */
export function todayAt(now: Date = new Date()): string {
  return new Intl.DateTimeFormat('sv-SE', {
    timeZone: TIMEZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(now)
}

export function isValidDate(value: string | null): value is string {
  if (value === null || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return false
  }
  const { year, month, day } = parts(value)

  // Обратная сборка отсекает 2026-02-30: Date.UTC молча сделал бы 2 марта.
  return ymd(year, month, day) === value
}

/**
 * Первое число месяца или квартала: полных дней в нём ещё нет, и пресет,
 * который начинается сегодня и кончается вчера, недоступен.
 */
export function isPresetAvailable(preset: PresetId, today: string): boolean {
  const { month, day } = parts(today)
  if (preset === 'month') {
    return day > 1
  }
  if (preset === 'quarter') {
    return !(day === 1 && (month - 1) % 3 === 0)
  }

  return true
}

/** Свой период, приведённый к тому, что примет API. */
export function clampCustom(range: DateRange, today: string): DateRange {
  const yesterday = addDays(today, -1)
  let from = range.from <= range.to ? range.from : range.to
  let to = range.from <= range.to ? range.to : range.from
  if (to > yesterday) {
    to = yesterday
  }
  if (from > to) {
    from = to
  }
  if (from < EARLIEST_DATE) {
    from = EARLIEST_DATE
  }
  if (to < from) {
    to = from
  }
  if (dayCount({ from, to }) > MAX_PERIOD_DAYS) {
    from = addDays(to, -(MAX_PERIOD_DAYS - 1))
  }

  return { from, to }
}

export function rangeFor(selection: PeriodSelection, today: string): DateRange {
  const yesterday = addDays(today, -1)
  const { year, month } = parts(today)
  const quarterStartMonth = month - ((month - 1) % 3)

  switch (selection.preset) {
    case 'yesterday':
      return { from: yesterday, to: yesterday }
    case '30d':
      return { from: addDays(yesterday, -29), to: yesterday }
    case 'month':
      return { from: ymd(year, month, 1), to: yesterday }
    case 'prevMonth':
      return { from: ymd(year, month - 1, 1), to: ymd(year, month, 0) }
    case 'quarter':
      return { from: ymd(year, quarterStartMonth, 1), to: yesterday }
    case 'custom':
      return clampCustom(
        selection.custom ?? { from: addDays(yesterday, -6), to: yesterday },
        today,
      )
    case '7d':
      return { from: addDays(yesterday, -6), to: yesterday }
  }
}

/** Та же дата год назад; 29 февраля — 28-е. */
function yearBefore(date: string): string {
  const { year, month, day } = parts(date)
  const lastDay = parts(ymd(year - 1, month + 1, 0)).day

  return ymd(year - 1, month, Math.min(day, lastDay))
}

/**
 * Период сравнения. «Пред. период» — той же длины сразу перед выбранным,
 * кроме начатых месяца и квартала: их сравнивают с тем же числом дней
 * прошлого месяца или квартала (не дальше его конца), а прошлый месяц —
 * с позапрошлым целиком. «Год назад» — те же даты.
 *
 * Сравнение всегда кончается раньше начала выбранного — API отвергает
 * пересечение, потому что общие дни молча ушли бы в выбранный период.
 */
export function comparisonFor(
  selection: PeriodSelection,
  range: DateRange,
): DateRange {
  if (selection.compare === 'year') {
    const to = yearBefore(range.to)
    const lastBefore = addDays(range.from, -1)

    // Период ровно в 366 дней без 29 февраля: год назад от его конца —
    // это его же первый день. Сравнение обрезается, а не пересекается.
    return {
      from: yearBefore(range.from),
      to: to < range.from ? to : lastBefore,
    }
  }

  const { year, month } = parts(range.from)
  if (selection.preset === 'month' || selection.preset === 'quarter') {
    const step = selection.preset === 'month' ? 1 : 3
    const from = ymd(year, month - step, 1)
    const lastOfPrevious = addDays(range.from, -1)
    const to = addDays(from, dayCount(range) - 1)

    return { from, to: to > lastOfPrevious ? lastOfPrevious : to }
  }
  if (selection.preset === 'prevMonth') {
    return { from: ymd(year, month - 1, 1), to: ymd(year, month, 0) }
  }

  const to = addDays(range.from, -1)

  return { from: addDays(to, -(dayCount(range) - 1)), to }
}

function parsePreset(value: string | null): PresetId | null {
  return PRESETS.find((preset) => preset.id === value)?.id ?? null
}

function parseCompare(value: string | null): CompareMode | null {
  return COMPARE_MODES.find((mode) => mode.id === value)?.id ?? null
}

/**
 * Выбор из адресной строки. Мусор молча заменяется запасным выбором
 * (последним сохранённым или умолчанием) — так же, как в остальных
 * отчётах: автор строки — человек со старой ссылкой, а не наш код,
 * и экран ошибки ему ничем не поможет.
 */
export function parseSelection(
  search: URLSearchParams,
  fallback: PeriodSelection,
  today: string,
): PeriodSelection {
  const compare = parseCompare(search.get('compare')) ?? fallback.compare
  let preset = parsePreset(search.get('period')) ?? fallback.preset
  let custom: DateRange | null = null

  if (preset === 'custom') {
    const from = search.get('from')
    const to = search.get('to')
    if (isValidDate(from) && isValidDate(to)) {
      custom = clampCustom({ from, to }, today)
    } else if (fallback.preset === 'custom' && fallback.custom !== null) {
      custom = clampCustom(fallback.custom, today)
    } else {
      preset = DEFAULT_PRESET
    }
  }

  if (!isPresetAvailable(preset, today)) {
    preset = DEFAULT_PRESET
  }

  return { preset, compare, custom }
}

/** Адресная строка с выбором; остальные параметры не трогаются. */
export function searchWithSelection(
  search: URLSearchParams,
  selection: PeriodSelection,
): URLSearchParams {
  const next = new URLSearchParams(search)
  next.set('period', selection.preset)
  next.set('compare', selection.compare)
  if (selection.preset === 'custom' && selection.custom !== null) {
    next.set('from', selection.custom.from)
    next.set('to', selection.custom.to)
  } else {
    next.delete('from')
    next.delete('to')
  }

  return next
}

function dayLabel(date: string): string {
  const { month, day } = parts(date)

  return `${String(day)} ${MONTHS[month - 1] ?? ''}`
}

function dayLabelWithYear(date: string): string {
  return `${dayLabel(date)} ${String(parts(date).year)}`
}

/** «8 авг – 14 авг 2026» или «14 авг 2026» для одного дня. */
export function rangeLabel(range: DateRange): string {
  if (range.from === range.to) {
    return dayLabelWithYear(range.from)
  }
  const sameYear = parts(range.from).year === parts(range.to).year

  return `${sameYear ? dayLabel(range.from) : dayLabelWithYear(range.from)} – ${dayLabelWithYear(range.to)}`
}

/** «7 дн.», «1 день». */
export function dayCountLabel(range: DateRange): string {
  const days = dayCount(range)

  return days === 1 ? '1 день' : `${String(days)} дн.`
}
