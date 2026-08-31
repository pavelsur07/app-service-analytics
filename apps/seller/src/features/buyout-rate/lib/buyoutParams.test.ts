import { describe, expect, it } from 'vitest'

import {
  buyoutSearchWithCursor,
  buyoutSearchWithDays,
  buyoutSearchWithSort,
  nextBuyoutSort,
  parseBuyoutSort,
  parseBuyoutSortDirection,
  parseBuyoutDays,
} from './buyoutParams'

describe('параметры адреса экрана выкупа', () => {
  it('принимает только окна 7, 30 и 90 дней', () => {
    expect(parseBuyoutDays('7')).toBe(7)
    expect(parseBuyoutDays('30')).toBe(30)
    expect(parseBuyoutDays('90')).toBe(90)
  })

  it('заменяет отсутствующее или неизвестное окно на 30 дней', () => {
    expect(parseBuyoutDays(null)).toBe(30)
    expect(parseBuyoutDays('')).toBe(30)
    expect(parseBuyoutDays('14')).toBe(30)
    expect(parseBuyoutDays('abc')).toBe(30)
  })

  it('смена окна сохраняет посторонние параметры и удаляет cursor', () => {
    const next = buyoutSearchWithDays(
      new URLSearchParams('days=30&cursor=next-page&source=bookmark'),
      7,
    )

    expect(next.toString()).toBe(
      'days=7&source=bookmark&sort=ordered&direction=desc',
    )
  })

  it('записывает и очищает курсор текущей страницы', () => {
    const search = new URLSearchParams('days=30')

    expect(buyoutSearchWithCursor(search, 'SKU+/= cursor').toString()).toBe(
      'days=30&sort=ordered&direction=desc&cursor=SKU%2B%2F%3D+cursor',
    )
    expect(
      buyoutSearchWithCursor(
        new URLSearchParams('days=30&cursor=next-page'),
        null,
      ).toString(),
    ).toBe('days=30&sort=ordered&direction=desc')
  })

  it('разбирает порядок и заменяет мусор безопасными значениями', () => {
    expect(parseBuyoutSort('actual_buyout')).toBe('actual_buyout')
    expect(parseBuyoutSort('unknown')).toBe('ordered')
    expect(parseBuyoutSortDirection('asc')).toBe('asc')
    expect(parseBuyoutSortDirection('sideways')).toBe('desc')
  })

  it('активная колонка меняет направление, новая начинает с убывания', () => {
    expect(nextBuyoutSort('ordered', 'ordered', 'desc')).toEqual({
      sort: 'ordered',
      direction: 'asc',
    })
    expect(nextBuyoutSort('actual_buyout', 'ordered', 'asc')).toEqual({
      sort: 'actual_buyout',
      direction: 'desc',
    })
  })

  it('смена сортировки сохраняет окно и сбрасывает курсор', () => {
    const next = buyoutSearchWithSort(
      new URLSearchParams(
        'days=90&sort=ordered&direction=desc&cursor=next&source=bookmark',
      ),
      'actual_buyout',
      'desc',
    )

    expect(next.toString()).toBe(
      'days=90&sort=actual_buyout&direction=desc&source=bookmark',
    )
  })
})
