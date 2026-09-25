import { describe, expect, it } from 'vitest'
import {
  canGoForward,
  cellTitle,
  currentMonth,
  dayNumber,
  monthLabel,
  shiftMonth,
  statusView,
} from './coverage'

const ROW = {
  key: 'ozon_posting_fbo_list',
  section: 'Продажи',
  endpoint: 'POST /v2/posting/fbo/list',
  statuses: [],
  lastReceivedAt: [],
  covered: 0,
  due: 0,
}

describe('переключатель месяца', () => {
  it('листает через границу года в обе стороны', () => {
    expect(shiftMonth('2026-09', -1)).toBe('2026-08')
    expect(shiftMonth('2026-01', -1)).toBe('2025-12')
    expect(shiftMonth('2026-12', 1)).toBe('2027-01')
  })

  it('подписывает месяц по-русски', () => {
    expect(monthLabel('2026-09')).toBe('сентябрь 2026')
  })

  it('не пускает в будущий месяц', () => {
    expect(canGoForward('2026-08', '2026-09')).toBe(true)
    expect(canGoForward('2026-09', '2026-09')).toBe(false)
  })

  it('считает текущий месяц по Москве, а не по часам браузера', () => {
    // 31 августа 22:30 UTC — в Москве уже 1 сентября.
    expect(currentMonth(new Date('2026-08-31T22:30:00Z'))).toBe('2026-09')
  })
})

describe('ячейка карты', () => {
  it('у каждого статуса свой вид, ошибка отличается ещё и знаком', () => {
    const views = (['loaded', 'missing', 'failed', 'pending'] as const).map(
      (status) => statusView(status),
    )
    expect(new Set(views.map((view) => view.cell)).size).toBe(4)
    expect(statusView('failed').mark).not.toBe('')
    expect(statusView('loaded').mark).toBe('')
  })

  it('подсказка называет день, эндпоинт, статус и время загрузки по Москве', () => {
    expect(dayNumber('2026-09-05')).toBe('5')
    expect(
      cellTitle('2026-09-05', ROW, 'loaded', '2026-09-05T08:55:00+00:00'),
    ).toBe(
      '5 сентября · POST /v2/posting/fbo/list · загружено · последняя загрузка 05.09, 11:55',
    )
    expect(cellTitle('2026-09-06', ROW, 'missing', null)).toBe(
      '6 сентября · POST /v2/posting/fbo/list · нет данных',
    )
  })
})
