import { useState } from 'react'
import {
  CircleAlert,
  CircleCheck,
  CircleOff,
  CircleX,
  Clock,
  LoaderCircle,
  RefreshCw,
  TriangleAlert,
} from 'lucide-react'
import type { ReactNode } from 'react'

import {
  Badge,
  Button,
  Card,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import {
  cellKey,
  cellState,
  dailyMatrix,
  formatMoscowTime,
  periodicRows,
  problems,
  resourceLabel,
  verdict,
  type CellState,
  type Problem,
  type ProblemKind,
  type Verdict,
} from '../model/coverage'
import {
  useServiceCoverage,
  type CoverageDays,
  type ServiceCoverageResponse,
  type ServiceCoverageRow,
} from '../model/useServiceCoverage'
import { CELL_APPEARANCE } from './cellAppearance'

const VERDICT: Record<
  Verdict,
  {
    title: string
    description: string
    tone: 'positive' | 'negative' | 'warning' | 'neutral'
    icon: ReactNode
  }
> = {
  ok: {
    title: 'В порядке',
    description: 'Все прошедшие дни загружены и совпадают с PHP.',
    tone: 'positive',
    icon: <CircleCheck aria-hidden="true" size={16} />,
  },
  lagging: {
    title: 'Есть отставание',
    description: 'Часть дней не загружена или расходится с PHP — список ниже.',
    tone: 'warning',
    icon: <TriangleAlert aria-hidden="true" size={16} />,
  },
  attention: {
    title: 'Нужен человек',
    description: 'Есть загрузки, которые сервис не повторит сам.',
    tone: 'negative',
    icon: <CircleAlert aria-hidden="true" size={16} />,
  },
  unavailable: {
    title: 'Сервис не отвечает',
    description:
      'Последняя попытка снять сводку не удалась: сеть, ошибка или ответ вне контракта. Ниже — последняя успешная сводка; текущее покрытие неизвестно — это не ноль.',
    tone: 'negative',
    icon: <CircleX aria-hidden="true" size={16} />,
  },
  disabled: {
    title: 'Сервис выключен',
    description:
      'Адрес или токен сервиса у worker-service-coverage не заданы, либо загрузка в сервисе выключена.',
    tone: 'neutral',
    icon: <CircleOff aria-hidden="true" size={16} />,
  },
  not_ready: {
    title: 'Сервис запускается',
    description:
      'Сервис ещё не получил список подключений — обновите через минуту.',
    tone: 'neutral',
    icon: <Clock aria-hidden="true" size={16} />,
  },
  never: {
    title: 'Сводку ещё не снимали',
    description:
      'worker-service-coverage ещё ни разу не обращался к сервису — проверьте, что он запущен.',
    tone: 'neutral',
    icon: <Clock aria-hidden="true" size={16} />,
  },
  capture_stale: {
    title: 'Сводка не обновляется',
    description:
      'Последняя попытка снять сводку была больше 15 минут назад — worker-service-coverage, похоже, стоит. Ниже — последняя полученная сводка.',
    tone: 'warning',
    icon: <TriangleAlert aria-hidden="true" size={16} />,
  },
}

const PROBLEM_LABEL: Record<ProblemKind, string> = {
  attention: 'Нужен человек',
  php_mismatch: 'Расхождение с PHP',
  missing: 'Не загружено',
  stale: 'Отставание',
  retrying: 'Повторяется',
}

const PROBLEM_TONE: Record<ProblemKind, 'negative' | 'warning'> = {
  attention: 'negative',
  php_mismatch: 'warning',
  missing: 'warning',
  stale: 'warning',
  retrying: 'warning',
}

const LEGEND_ORDER: CellState[] = [
  'loaded',
  'partial',
  'missing',
  'in_progress',
  'not_ready',
  'retrying',
  'attention',
  'no_data',
]

// Экран «Покрытие GO»: покрытие ozon-fetcher рядом с загрузкой PHP
// (ADR-043 п. 6). Доступен обеим ролям контура — только чтение.
export function ServiceCoveragePage() {
  const [days, setDays] = useState<CoverageDays>(31)
  const coverage = useServiceCoverage(days)

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-semibold">Покрытие GO</h1>
          <p className="text-sm text-text-muted">
            ozon-fetcher рядом с загрузкой PHP · даты по МСК
          </p>
        </div>
        <div className="flex items-center gap-2">
          <div className="flex gap-1" role="group" aria-label="Окно">
            {([31, 93] as const).map((option) => (
              <Button
                aria-pressed={days === option}
                key={option}
                onClick={() => {
                  setDays(option)
                }}
                size="compact"
                type="button"
                variant={days === option ? 'primary' : 'secondary'}
              >
                {option} дн.
              </Button>
            ))}
          </div>
          <Button
            disabled={coverage.isFetching}
            onClick={() => {
              void coverage.refetch()
            }}
            size="compact"
            type="button"
            variant="secondary"
          >
            <RefreshCw aria-hidden="true" size={14} />
            Обновить
          </Button>
        </div>
      </header>

      {coverage.status === 'pending' && (
        <Card>
          <StatusPanel
            icon={
              <LoaderCircle
                aria-hidden="true"
                className="animate-spin"
                size={20}
              />
            }
            title="Загружаем покрытие"
          />
        </Card>
      )}

      {coverage.status === 'error' && (
        <Card>
          <StatusPanel
            action={
              <Button
                onClick={() => {
                  void coverage.refetch()
                }}
                size="compact"
                type="button"
                variant="secondary"
              >
                Повторить
              </Button>
            }
            description={
              coverage.error instanceof Error
                ? coverage.error.message
                : 'Попробуйте обновить страницу.'
            }
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Не удалось загрузить покрытие"
            tone="negative"
          />
        </Card>
      )}

      {coverage.status === 'success' && <Coverage data={coverage.data} />}
    </div>
  )
}

function Coverage({ data }: { data: ServiceCoverageResponse }) {
  const found = problems(data)
  const summary = VERDICT[verdict(data, found)]

  return (
    <>
      <Card>
        <div className="flex flex-col gap-3">
          <div className="flex flex-wrap items-center gap-3">
            <Badge tone={summary.tone}>
              {summary.icon}
              {summary.title}
            </Badge>
            <span className="text-sm text-text-secondary">
              {summary.description}
            </span>
          </div>
          <dl className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
            <Fact label="Сводка сервиса">
              {data.serviceGeneratedAt != null
                ? formatMoscowTime(data.serviceGeneratedAt)
                : '—'}
            </Fact>
            <Fact label="Последняя попытка">
              {data.attemptedAt != null
                ? formatMoscowTime(data.attemptedAt)
                : '—'}
            </Fact>
            <Fact label="Кабинеты Seller / Performance">
              {data.cabinetsSeller != null
                ? `${String(data.cabinetsSeller)} / ${String(data.cabinetsPerformance ?? 0)}`
                : '—'}
            </Fact>
            <Fact label="Окно">
              {data.from} — {data.to}
            </Fact>
          </dl>
          {!data.phpCompared && (
            <p className="text-sm text-warning-text">
              Allowlist сервиса в api не задан — загрузка PHP не сравнивается.
            </p>
          )}
        </div>
      </Card>

      {found.length > 0 && <Problems found={found} />}

      {data.rows.length > 0 && <Matrix data={data} />}
      <Periodic rows={periodicRows(data)} />
      <Legend />
    </>
  )
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-xs text-text-muted">{label}</dt>
      <dd className="tabular-nums">{children}</dd>
    </div>
  )
}

function Problems({ found }: { found: Problem[] }) {
  return (
    <Card
      tone={found.some((p) => p.kind === 'attention') ? 'negative' : 'warning'}
    >
      <h2 className="mb-3 text-lg font-semibold">
        Проблемы <span className="text-text-muted">· {found.length}</span>
      </h2>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-xs text-text-muted">
              <th className="py-2 pr-4">Что</th>
              <th className="py-2 pr-4">Ресурс</th>
              <th className="py-2 pr-4">Период</th>
              <th className="py-2">Подробности</th>
            </tr>
          </thead>
          <tbody>
            {found.map((problem) => (
              <tr
                className="border-t border-border-default"
                key={`${problem.kind}|${problem.resourceType}|${problem.periodKey}`}
              >
                <td className="py-2 pr-4">
                  <Badge size="compact" tone={PROBLEM_TONE[problem.kind]}>
                    {PROBLEM_LABEL[problem.kind]}
                  </Badge>
                </td>
                <td className="py-2 pr-4">
                  {resourceLabel(problem.resourceType)}
                </td>
                <td className="py-2 pr-4 tabular-nums">
                  {problem.periodKey === '' ? 'весь ресурс' : problem.periodKey}
                </td>
                <td className="py-2 text-text-secondary">{problem.detail}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  )
}

function cellTitle(
  row: ServiceCoverageRow | undefined,
  state: CellState,
): string {
  const parts = [CELL_APPEARANCE[state].label]
  if (row !== undefined) {
    if (row.cabinetsExpected != null) {
      parts.push(
        `загружено ${String(row.done)} из ${String(row.cabinetsExpected)}`,
        `в работе ${String(row.running + row.pending)}, не готов ${String(row.notReady)}, повтор ${String(row.failedRetryable)}, без повтора ${String(row.failedPermanent)}`,
      )
    }
    if (row.phpCabinets != null) {
      parts.push(`PHP загрузил ${String(row.phpCabinets)}`)
    }
  }
  return parts.join('; ')
}

function Matrix({ data }: { data: ServiceCoverageResponse }) {
  const matrix = dailyMatrix(data)
  if (matrix.resources.length === 0) {
    return null
  }

  return (
    <Card>
      <h2 className="mb-1 text-lg font-semibold">По дням</h2>
      <p className="mb-3 text-sm text-text-muted">
        Ячейка — загружено из ожидаемых кабинетов; точка под ячейкой — день
        загружен PHP.
      </p>
      <div className="overflow-x-auto">
        <table className="border-separate border-spacing-0.5 text-xs">
          <thead>
            <tr>
              <th className="sticky left-0 bg-surface-raised pr-3 text-left font-normal text-text-muted">
                Ресурс
              </th>
              {matrix.days.map((day) => (
                <th
                  className="w-7 font-normal text-text-muted tabular-nums"
                  key={day}
                  scope="col"
                >
                  <time dateTime={day} title={day}>
                    {day.slice(8)}
                  </time>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {matrix.resources.map((resource) => (
              <tr key={resource}>
                <th
                  className="sticky left-0 whitespace-nowrap bg-surface-raised pr-3 text-left font-medium"
                  scope="row"
                >
                  {resourceLabel(resource)}
                  <span className="block text-xs font-normal text-text-faint">
                    {resource}
                  </span>
                </th>
                {matrix.days.map((day) => {
                  const row = matrix.cells.get(cellKey(resource, day))
                  const state = cellState(row)
                  const appearance = CELL_APPEARANCE[state]
                  const title = cellTitle(row, state)
                  return (
                    <td key={day} title={`${day}: ${title}`}>
                      <span
                        className={`grid size-7 place-items-center rounded border ${appearance.className}`}
                      >
                        {appearance.icon}
                        <span className="sr-only">{`${day}: ${title}`}</span>
                      </span>
                      <span
                        aria-hidden="true"
                        className={`mx-auto mt-0.5 block size-1 rounded-full ${
                          (row?.phpCabinets ?? 0) > 0
                            ? 'bg-text-secondary'
                            : 'bg-transparent'
                        }`}
                      />
                    </td>
                  )
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  )
}

function Periodic({ rows }: { rows: ServiceCoverageRow[] }) {
  if (rows.length === 0) {
    return null
  }

  return (
    <Card>
      <h2 className="mb-3 text-lg font-semibold">Справочники и месяцы</h2>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-xs text-text-muted">
              <th className="py-2 pr-4">Ресурс</th>
              <th className="py-2 pr-4">Период</th>
              <th className="py-2 pr-4">Состояние</th>
              <th className="py-2 pr-4 text-right">Загружено</th>
              <th className="py-2">Последняя успешная</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const appearance = CELL_APPEARANCE[cellState(row)]
              return (
                <tr
                  className="border-t border-border-default"
                  key={`${row.resourceType}|${row.periodKey}`}
                >
                  <td className="py-2 pr-4">
                    {resourceLabel(row.resourceType)}
                  </td>
                  <td className="py-2 pr-4 tabular-nums">
                    {row.periodKey === 'ref' ? 'справочник' : row.periodKey}
                  </td>
                  <td className="py-2 pr-4">
                    <span
                      className={`inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-xs ${appearance.className}`}
                    >
                      {appearance.icon}
                      {appearance.label}
                    </span>
                  </td>
                  <td className="py-2 pr-4 text-right tabular-nums">
                    {row.done} / {row.cabinetsExpected ?? '—'}
                  </td>
                  <td className="py-2 tabular-nums">
                    {row.lastSuccessAt != null
                      ? formatMoscowTime(row.lastSuccessAt)
                      : '—'}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </Card>
  )
}

function Legend() {
  return (
    <Card>
      <h2 className="mb-3 text-lg font-semibold">Легенда</h2>
      <ul className="flex flex-wrap gap-x-5 gap-y-2 text-sm">
        {LEGEND_ORDER.map((state) => (
          <li className="flex items-center gap-2" key={state}>
            <span
              className={`grid size-6 place-items-center rounded border ${CELL_APPEARANCE[state].className}`}
            >
              {CELL_APPEARANCE[state].icon}
            </span>
            {CELL_APPEARANCE[state].label}
          </li>
        ))}
      </ul>
      <p className="mt-3 text-sm text-text-muted">
        «Отчёт не готов» и «в работе» за последние двое суток — норма: площадка
        ещё формирует отчёты. Экран показывает только счётчики — данных компаний
        и ключей здесь нет.
      </p>
    </Card>
  )
}
