import { describe, expect, it } from 'vitest'
import { cellTitle, dayNumber, statusView } from './coverage'

const ROW = {
  key: 'ozon_posting_fbo_list',
  section: 'Продажи',
  endpoint: 'POST /v2/posting/fbo/list',
  statuses: [],
  lastReceivedAt: [],
  covered: 0,
  due: 0,
}

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
