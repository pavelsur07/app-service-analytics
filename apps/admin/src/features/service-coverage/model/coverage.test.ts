import { describe, expect, it } from 'vitest'

import {
  cellState,
  dailyMatrix,
  periodicRows,
  problems,
  verdict,
  windowDays,
} from './coverage'
import type {
  ServiceCoverageResponse,
  ServiceCoverageRow,
} from './useServiceCoverage'

function row(overrides: Partial<ServiceCoverageRow>): ServiceCoverageRow {
  return {
    resourceType: 'ozon.orders.fbo',
    periodKey: '2026-10-20',
    cabinetsExpected: 1,
    done: 1,
    running: 0,
    pending: 0,
    notReady: 0,
    failedRetryable: 0,
    failedPermanent: 0,
    lastSuccessAt: '2026-10-25T08:00:00+00:00',
    phpCabinets: 1,
    ...overrides,
  }
}

function coverage(
  rows: ServiceCoverageRow[],
  overrides: Partial<ServiceCoverageResponse> = {},
): ServiceCoverageResponse {
  return {
    from: '2026-10-18',
    to: '2026-10-25',
    checkedAt: '2026-10-25T09:00:00+00:00',
    serviceState: 'ok',
    attemptedAt: '2026-10-25T08:58:00+00:00',
    serviceGeneratedAt: '2026-10-25T08:58:00+00:00',
    cabinetsSeller: 1,
    cabinetsPerformance: 1,
    phpCompared: true,
    rows,
    ...overrides,
  }
}

describe('состояние ячейки', () => {
  it('тяжёлое состояние важнее загруженного', () => {
    expect(cellState(row({ failedPermanent: 1, done: 1 }))).toBe('attention')
    expect(cellState(row({ failedRetryable: 1 }))).toBe('retrying')
    expect(cellState(row({ pending: 1, done: 0 }))).toBe('in_progress')
    expect(cellState(row({ notReady: 1, done: 0 }))).toBe('not_ready')
  })

  it('знаменатель — ожидаемые кабинеты из ответа', () => {
    expect(cellState(row({ cabinetsExpected: 2, done: 2 }))).toBe('loaded')
    expect(cellState(row({ cabinetsExpected: 2, done: 1 }))).toBe('partial')
    expect(cellState(row({ cabinetsExpected: 2, done: 0 }))).toBe('missing')
  })

  it('нет строки или ничего не ожидается — «нет данных», а не ноль', () => {
    expect(cellState(undefined)).toBe('no_data')
    expect(cellState(row({ cabinetsExpected: null, done: 0 }))).toBe('no_data')
    expect(cellState(row({ cabinetsExpected: 0, done: 0 }))).toBe('no_data')
  })
})

describe('проблемы', () => {
  it('всё загружено и совпадает с PHP — проблем нет, вердикт «в порядке»', () => {
    const data = coverage([
      row({}),
      row({ periodKey: '2026-10-25', done: 0, running: 1 }),
    ])

    expect(problems(data)).toEqual([])
    expect(verdict(data, problems(data))).toBe('ok')
  })

  it('день, загруженный PHP и ни разу — сервисом, — расхождение', () => {
    const found = problems(
      coverage([
        row({ periodKey: '2026-10-25' }),
        row({ done: 0, pending: 1, lastSuccessAt: null, phpCabinets: 1 }),
      ]),
    )

    expect(found.map((p) => p.kind)).toEqual(['php_mismatch'])
  })

  it('день только у PHP в окне сервиса — тоже расхождение', () => {
    const found = problems(
      coverage([
        row({ periodKey: '2026-10-19' }),
        row({
          cabinetsExpected: null,
          done: 0,
          lastSuccessAt: null,
          phpCabinets: 1,
        }),
      ]),
    )

    expect(found.map((p) => p.kind)).toEqual(['php_mismatch'])
  })

  it('перезагрузка уже загруженного дня — не расхождение', () => {
    // Сервис ставит прошлые дни на перезагрузку: done = 0, успех уже был.
    const data = coverage([
      row({ periodKey: '2026-10-21', done: 0, running: 1, phpCabinets: 1 }),
      row({ periodKey: '2026-10-22', done: 0, pending: 1, phpCabinets: 1 }),
    ])

    expect(problems(data)).toEqual([])
  })

  it('день раньше окна сервиса не сравнивается', () => {
    // 93 дня захватывают месяц до M-2: задач сервиса там нет по плану.
    const data = coverage([
      row({ periodKey: '2026-10-19' }),
      row({
        periodKey: '2026-10-18',
        cabinetsExpected: null,
        done: 0,
        lastSuccessAt: null,
        phpCabinets: 1,
      }),
    ])

    expect(problems(data)).toEqual([])
  })

  it('последние двое суток пропуском не считаются', () => {
    const data = coverage([
      row({
        periodKey: '2026-10-24',
        done: 0,
        lastSuccessAt: null,
        phpCabinets: 1,
      }),
      row({
        periodKey: '2026-10-25',
        done: 0,
        notReady: 1,
        lastSuccessAt: null,
        phpCabinets: 0,
      }),
      row({ periodKey: '2026-10-23', done: 1 }),
    ])

    expect(problems(data)).toEqual([])
  })

  it('давний день без успешной загрузки — пропуск в любом статусе', () => {
    const found = problems(
      coverage([
        row({
          periodKey: '2026-10-20',
          done: 0,
          notReady: 1,
          lastSuccessAt: null,
          phpCabinets: 0,
        }),
        row({
          periodKey: '2026-10-21',
          done: 0,
          lastSuccessAt: null,
          phpCabinets: 0,
        }),
        row({
          periodKey: '2026-10-22',
          cabinetsExpected: 2,
          done: 1,
          phpCabinets: 0,
        }),
        row({ periodKey: '2026-10-25' }),
      ]),
    )

    expect(found.map((p) => [p.kind, p.periodKey])).toEqual([
      ['missing', '2026-10-22'],
      ['missing', '2026-10-21'],
      ['missing', '2026-10-20'],
    ])
  })

  it('«нужен человек» — первым и с вердиктом «нужен человек»', () => {
    const data = coverage([
      row({ periodKey: '2026-10-25' }),
      row({
        periodKey: '2026-10-19',
        done: 0,
        lastSuccessAt: null,
        phpCabinets: 0,
      }),
      row({ periodKey: '2026-10', failedPermanent: 1 }),
      row({
        periodKey: 'ref',
        failedRetryable: 1,
        resourceType: 'ozon.products.list',
      }),
    ])
    const found = problems(data)

    expect(found.map((p) => p.kind)).toEqual([
      'attention',
      'missing',
      'retrying',
    ])
    expect(verdict(data, found)).toBe('attention')
  })

  it('ресурс без успешной загрузки дольше 36 часов — отставание', () => {
    const data = coverage([
      row({
        periodKey: '2026-10-25',
        done: 0,
        running: 1,
        lastSuccessAt: '2026-10-23T20:00:00+00:00',
      }),
      row({
        resourceType: 'ozon.ads.daily',
        periodKey: '2026-10-25',
        done: 0,
        notReady: 1,
        lastSuccessAt: null,
        phpCabinets: 0,
      }),
    ])
    const found = problems(data)

    expect(found.map((p) => [p.kind, p.resourceType])).toEqual(
      [
        ['stale', 'ozon.orders.fbo'],
        ['stale', 'ozon.ads.daily'],
      ].sort((a, b) => (a[1] ?? '').localeCompare(b[1] ?? '')),
    )
    expect(verdict(data, found)).toBe('lagging')
  })

  it('без единой сводки проблем не придумываем, вердикт — состояние сервиса', () => {
    for (const state of [
      'unavailable',
      'disabled',
      'not_ready',
      'never',
    ] as const) {
      const data = coverage(
        [row({ cabinetsExpected: null, done: 0, phpCabinets: 1 })],
        { serviceState: state, serviceGeneratedAt: null },
      )

      expect(problems(data)).toEqual([])
      expect(verdict(data, [])).toBe(state)
    }
  })

  it('неудачная попытка не стирает проблемы последней сводки', () => {
    const data = coverage(
      [
        row({
          periodKey: '2026-10-24',
          lastSuccessAt: '2026-10-25T08:00:00+00:00',
        }),
        row({ periodKey: '2026-10-20', failedPermanent: 1, done: 0 }),
      ],
      { serviceState: 'unavailable' },
    )
    const found = problems(data)

    expect(found.map((p) => p.kind)).toEqual(['attention'])
    expect(verdict(data, found)).toBe('unavailable')
  })

  it('отставание считается от времени сводки, а не открытия экрана', () => {
    const data = coverage(
      [
        row({
          periodKey: '2026-10-24',
          lastSuccessAt: '2026-10-20T08:00:00+00:00',
        }),
      ],
      {
        serviceGeneratedAt: '2026-10-20T09:00:00+00:00',
        attemptedAt: '2026-10-25T08:58:00+00:00',
      },
    )

    expect(problems(data)).toEqual([])
  })

  it('попытка старше 15 минут — сводка не обновляется', () => {
    const fresh = coverage([], { attemptedAt: '2026-10-25T08:45:00+00:00' })
    const stale = coverage([], { attemptedAt: '2026-10-25T08:44:00+00:00' })

    expect(verdict(fresh, [])).toBe('ok')
    expect(verdict(stale, [])).toBe('capture_stale')
  })

  it('остановленный воркер важнее исхода его последней попытки', () => {
    for (const state of ['unavailable', 'disabled', 'not_ready'] as const) {
      const data = coverage([], {
        serviceState: state,
        attemptedAt: '2026-10-25T07:00:00+00:00',
      })

      expect(verdict(data, [])).toBe('capture_stale')
    }
  })

  it('дни после старого снимка не сравниваются с PHP', () => {
    const data = coverage(
      [
        row({
          periodKey: '2026-10-19',
          lastSuccessAt: '2026-10-21T07:00:00+00:00',
        }),
        row({
          periodKey: '2026-10-20',
          cabinetsExpected: null,
          done: 0,
          lastSuccessAt: null,
          phpCabinets: 1,
        }),
        row({
          periodKey: '2026-10-19',
          resourceType: 'ozon.ads.daily',
          cabinetsExpected: null,
          done: 0,
          lastSuccessAt: null,
          phpCabinets: 1,
        }),
      ],
      {
        serviceState: 'unavailable',
        serviceGeneratedAt: '2026-10-21T08:00:00+00:00',
      },
    )

    expect(problems(data).map((p) => [p.kind, p.periodKey])).toEqual([
      ['php_mismatch', '2026-10-19'],
    ])
  })
})

describe('раскладка', () => {
  it('дни окна включительно, через смену месяца', () => {
    expect(windowDays('2026-09-29', '2026-10-02')).toEqual([
      '2026-09-29',
      '2026-09-30',
      '2026-10-01',
      '2026-10-02',
    ])
  })

  it('дневные ресурсы — в матрицу, месяцы и справочники — отдельно', () => {
    const data = coverage([
      row({ resourceType: 'ozon.ads.daily', periodKey: '2026-10-20' }),
      row({ periodKey: '2026-10-21' }),
      row({ resourceType: 'ozon.products.list', periodKey: 'ref' }),
    ])
    const matrix = dailyMatrix(data)

    expect(matrix.resources).toEqual(['ozon.ads.daily', 'ozon.orders.fbo'])
    expect(matrix.days).toHaveLength(8)
    expect(matrix.cells.get('ozon.orders.fbo|2026-10-21')?.done).toBe(1)
    expect(periodicRows(data).map((r) => r.periodKey)).toEqual(['ref'])
  })
})
