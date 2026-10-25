import type {
  ServiceCoverageResponse,
  ServiceCoverageRow,
} from './useServiceCoverage'

// Состояние ячейки «ресурс × период»; порядок проверок — по тяжести.
export type CellState =
  | 'attention'
  | 'retrying'
  | 'in_progress'
  | 'not_ready'
  | 'loaded'
  | 'partial'
  | 'missing'
  | 'no_data'

export type ProblemKind =
  'attention' | 'php_mismatch' | 'missing' | 'retrying' | 'stale'

export interface Problem {
  kind: ProblemKind
  resourceType: string
  // Y-m-d, Y-m или ref; у отставания — пусто: оно про ресурс целиком.
  periodKey: string
  detail: string
}

export type Verdict =
  'ok' | 'lagging' | 'attention' | 'unavailable' | 'disabled' | 'not_ready'

// Последние сутки отчёты площадки ещё формируются, и сервис и PHP
// догружают их по своему расписанию — как в bin/ozon-fetcher-observe.sh,
// пропуск считается только у дней старше двух суток.
const SETTLE_DAYS = 2

// Порог контроля свежести PHP (NotifyStaleAccountsAction), чтобы
// «отставание» значило одно и то же на обоих экранах.
const STALE_HOURS = 36

const DAY_PATTERN = /^\d{4}-\d{2}-\d{2}$/

const RESOURCE_LABELS: Record<string, string> = {
  'ozon.orders.fbo': 'Заказы FBO',
  'ozon.finance.accrual-by-day': 'Начисления по дням',
  'ozon.ads.expense': 'Расходы на рекламу',
  'ozon.ads.daily': 'Реклама по дням',
  'ozon.ads.campaigns': 'Рекламные кампании',
  'ozon.products.list': 'Список товаров',
  'ozon.products.info': 'Карточки товаров',
}

export function resourceLabel(resourceType: string): string {
  return RESOURCE_LABELS[resourceType] ?? resourceType
}

function isDay(periodKey: string): boolean {
  return DAY_PATTERN.test(periodKey)
}

export function cellState(row: ServiceCoverageRow | undefined): CellState {
  const expected = row?.cabinetsExpected ?? 0
  if (row === undefined || expected === 0) {
    return 'no_data'
  }
  if (row.failedPermanent > 0) return 'attention'
  if (row.failedRetryable > 0) return 'retrying'
  if (row.running + row.pending > 0) return 'in_progress'
  if (row.notReady > 0) return 'not_ready'
  if (row.done >= expected) return 'loaded'
  if (row.done > 0) return 'partial'
  return 'missing'
}

// Дни окна по порядку, включительно. Даты — строки по Москве: арифметика
// в UTC не сдвигает их часовым поясом браузера.
export function windowDays(from: string, to: string): string[] {
  const days: string[] = []
  const cursor = new Date(`${from}T00:00:00Z`)
  const end = new Date(`${to}T00:00:00Z`)
  while (cursor <= end) {
    days.push(cursor.toISOString().slice(0, 10))
    cursor.setUTCDate(cursor.getUTCDate() + 1)
  }
  return days
}

function shiftDay(day: string, offset: number): string {
  const date = new Date(`${day}T00:00:00Z`)
  date.setUTCDate(date.getUTCDate() + offset)
  return date.toISOString().slice(0, 10)
}

// Последний день, пропуск которого уже считается проблемой.
function settledUntil(to: string): string {
  return shiftDay(to, -SETTLE_DAYS)
}

function rowProblem(row: ServiceCoverageRow, settled: string): Problem | null {
  const state = cellState(row)
  const base = { resourceType: row.resourceType, periodKey: row.periodKey }
  const expected = row.cabinetsExpected ?? 0
  if (state === 'attention') {
    return {
      ...base,
      kind: 'attention',
      detail: `ошибка без повтора: ${String(row.failedPermanent)} из ${String(expected)}`,
    }
  }
  const recent = isDay(row.periodKey) && row.periodKey > settled
  const php = row.phpCabinets ?? null
  if (!recent && php !== null && php > row.done) {
    return {
      ...base,
      kind: 'php_mismatch',
      detail: `PHP загрузил ${String(php)}, сервис — ${String(row.done)}`,
    }
  }
  if (
    !recent &&
    isDay(row.periodKey) &&
    (state === 'missing' || state === 'partial')
  ) {
    return {
      ...base,
      kind: 'missing',
      detail: `загружено ${String(row.done)} из ${String(expected)}`,
    }
  }
  if (state === 'retrying') {
    return {
      ...base,
      kind: 'retrying',
      detail: `ошибка, повтор: ${String(row.failedRetryable)} из ${String(expected)}`,
    }
  }
  return null
}

// Ресурс с дневными периодами без успешной загрузки дольше порога.
function staleProblems(data: ServiceCoverageResponse): Problem[] {
  const latest = new Map<string, number | null>()
  for (const row of data.rows) {
    if (!isDay(row.periodKey) || (row.cabinetsExpected ?? 0) === 0) continue
    const at = row.lastSuccessAt != null ? Date.parse(row.lastSuccessAt) : null
    const known = latest.get(row.resourceType)
    if (
      known === undefined ||
      (at !== null && (known === null || at > known))
    ) {
      latest.set(row.resourceType, at)
    }
  }
  const checkedAt = Date.parse(data.checkedAt)
  const problems: Problem[] = []
  for (const [resourceType, at] of latest) {
    if (at === null || checkedAt - at > STALE_HOURS * 3_600_000) {
      problems.push({
        kind: 'stale',
        resourceType,
        periodKey: '',
        detail:
          at === null
            ? 'ни одной успешной загрузки за окно'
            : `последняя успешная загрузка ${formatMoscowTime(new Date(at).toISOString())}`,
      })
    }
  }
  return problems
}

const SEVERITY: Record<ProblemKind, number> = {
  attention: 0,
  php_mismatch: 1,
  missing: 2,
  stale: 3,
  retrying: 4,
}

// Проблемы — только при ответившем сервисе: без сводки сервиса любой
// день выглядел бы пропущенным.
export function problems(data: ServiceCoverageResponse): Problem[] {
  if (data.serviceState !== 'ok') {
    return []
  }
  const settled = settledUntil(data.to)
  const found: Problem[] = [...staleProblems(data)]
  for (const row of data.rows) {
    const problem = rowProblem(row, settled)
    if (problem !== null) found.push(problem)
  }
  return found.sort(
    (a, b) =>
      SEVERITY[a.kind] - SEVERITY[b.kind] ||
      b.periodKey.localeCompare(a.periodKey) ||
      a.resourceType.localeCompare(b.resourceType),
  )
}

export function verdict(
  data: ServiceCoverageResponse,
  found: readonly Problem[],
): Verdict {
  if (data.serviceState !== 'ok') {
    return data.serviceState
  }
  if (found.some((p) => p.kind === 'attention')) return 'attention'
  return found.length > 0 ? 'lagging' : 'ok'
}

export interface DailyMatrix {
  days: string[]
  resources: string[]
  cells: Map<string, ServiceCoverageRow>
}

export function cellKey(resourceType: string, day: string): string {
  return `${resourceType}|${day}`
}

// Дневные ресурсы — матрицей; месяцы и справочники — отдельным списком.
export function dailyMatrix(data: ServiceCoverageResponse): DailyMatrix {
  const cells = new Map<string, ServiceCoverageRow>()
  const resources = new Set<string>()
  for (const row of data.rows) {
    if (!isDay(row.periodKey)) continue
    resources.add(row.resourceType)
    cells.set(cellKey(row.resourceType, row.periodKey), row)
  }
  return {
    days: windowDays(data.from, data.to),
    resources: [...resources].sort(),
    cells,
  }
}

export function periodicRows(
  data: ServiceCoverageResponse,
): ServiceCoverageRow[] {
  return data.rows.filter((row) => !isDay(row.periodKey))
}

const MOSCOW_TIME = new Intl.DateTimeFormat('ru-RU', {
  timeZone: 'Europe/Moscow',
  day: '2-digit',
  month: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
})

// Время ответа и загрузок — по Москве, как и даты периодов.
export function formatMoscowTime(iso: string): string {
  return MOSCOW_TIME.format(new Date(iso))
}
