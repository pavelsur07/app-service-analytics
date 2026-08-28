import { describe, expect, it } from 'vitest'
import {
  initialDirection,
  nextSort,
  parseDays,
  parseDirection,
  parsePageSize,
  parseSort,
} from './tableParams'

describe('разбор параметров адреса', () => {
  it('берёт только размеры страницы из списка', () => {
    expect(parsePageSize('25')).toBe(25)
    expect(parsePageSize('30')).toBe(30)
    expect(parsePageSize('40')).toBe(40)
  })

  // Адрес правит человек, и устаревшая закладка должна показать экран,
  // а не сломать его. Строгость — на бэкенде, где строку собираем мы сами.
  it('заменяет мусор умолчанием, а не падает', () => {
    expect(parsePageSize('50')).toBe(25)
    expect(parsePageSize('abc')).toBe(25)
    expect(parsePageSize('')).toBe(25)
    expect(parsePageSize(null)).toBe(25)
    expect(parsePageSize('-30')).toBe(25)
    expect(parsePageSize('30.5')).toBe(25)
  })

  it('берёт только известные показатели сортировки', () => {
    expect(parseSort('margin')).toBe('margin')
    expect(parseSort('cost')).toBe('cost')
    expect(parseSort('profit')).toBe('revenue')
    expect(parseSort(null)).toBe('revenue')
  })

  it('берёт только два направления', () => {
    expect(parseDirection('asc')).toBe('asc')
    expect(parseDirection('desc')).toBe('desc')
    expect(parseDirection('sideways')).toBe('desc')
    expect(parseDirection(null)).toBe('desc')
  })

  it('берёт только окна из набора кнопок', () => {
    expect(parseDays('7')).toBe(7)
    expect(parseDays('90')).toBe(90)
    expect(parseDays('45')).toBe(30)
    expect(parseDays(null)).toBe(30)
  })
})

describe('сторона, с которой показатель интересен', () => {
  // Комиссия, расходы и себестоимость хранятся отрицательными. По
  // убыванию наверх всплыл бы самый дешёвый товар — обратное тому,
  // зачем на эту колонку нажимают.
  it('у отрицательных показателей первый клик даёт возрастание', () => {
    expect(initialDirection('commission')).toBe('asc')
    expect(initialDirection('expenses')).toBe('asc')
    expect(initialDirection('cost')).toBe('asc')
  })

  it('у положительных — убывание', () => {
    expect(initialDirection('revenue')).toBe('desc')
    expect(initialDirection('delivered')).toBe('desc')
    expect(initialDirection('margin')).toBe('desc')
  })
})

describe('клик по заголовку', () => {
  it('переворачивает активную колонку', () => {
    expect(nextSort('revenue', 'revenue', 'desc')).toEqual({
      sort: 'revenue',
      direction: 'asc',
    })
    expect(nextSort('revenue', 'revenue', 'asc')).toEqual({
      sort: 'revenue',
      direction: 'desc',
    })
  })

  it('неактивную берёт с её стороны, а не с текущей', () => {
    expect(nextSort('cost', 'revenue', 'desc')).toEqual({
      sort: 'cost',
      direction: 'asc',
    })
    expect(nextSort('margin', 'cost', 'asc')).toEqual({
      sort: 'margin',
      direction: 'desc',
    })
  })
})
