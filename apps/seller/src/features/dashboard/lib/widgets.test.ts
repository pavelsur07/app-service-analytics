import { describe, expect, it } from 'vitest'

import { changeView, formatValue, isWidgetId, WIDGETS } from './widgets'

// Intl разделяет разряды и валюту неразрывными пробелами; в ожиданиях
// обычные — сравнение по смыслу, а не по коду пробела.
function plain(text: string): string {
  return text.replace(/\s/gu, ' ')
}

describe('изменение к периоду сравнения', () => {
  it('рост выручки — лучше, рост возвратов — хуже', () => {
    expect(changeView(1_250, 'relative', 'up')).toEqual({
      text: '▲ +12,5%',
      title: 'Выросло на 12,5% к периоду сравнения — лучше',
      tone: 'positive',
    })
    expect(changeView(1_250, 'relative', 'down').tone).toBe('negative')
    expect(changeView(-2_500, 'relative', 'down')).toMatchObject({
      text: '▼ −25,0%',
      tone: 'positive',
    })
    expect(changeView(800, 'relative', 'neutral').tone).toBe('neutral')
  })

  it('проценты меняются в п.п.', () => {
    expect(changeView(200, 'points', 'down')).toMatchObject({
      text: '▲ +2,0 п.п.',
      tone: 'negative',
    })
  })

  it('округляет до десятой, половину от нуля', () => {
    expect(changeView(1_255, 'relative', 'up').text).toBe('▲ +12,6%')
    expect(changeView(-1_255, 'relative', 'up').text).toBe('▼ −12,6%')
    expect(changeView(1_254, 'relative', 'up').text).toBe('▲ +12,5%')
  })

  it('без изменений и без данных — нейтрально', () => {
    expect(changeView(4, 'relative', 'up')).toMatchObject({
      text: '— 0,0%',
      tone: 'neutral',
    })
    expect(changeView(null, 'points', 'up')).toMatchObject({
      text: '— нет данных',
      tone: 'neutral',
    })
    expect(changeView(undefined, 'relative', 'down').text).toBe('— нет данных')
  })
})

describe('значение показателя', () => {
  it('рубли целыми, цена заказа с копейками', () => {
    expect(plain(formatValue(12_345_678, 'money', 'RUB'))).toBe('123 457 ₽')
    expect(plain(formatValue(3_334, 'moneyPrecise', 'RUB'))).toBe('33,34 ₽')
  })

  it('штуки и проценты', () => {
    expect(plain(formatValue(1_234, 'quantity', 'RUB'))).toBe('1 234 шт.')
    expect(plain(formatValue(1_250, 'percent', 'RUB'))).toBe('12,5%')
  })

  it('нет значения — прочерк, а не ноль', () => {
    expect(plain(formatValue(null, 'moneyPrecise', 'RUB'))).toBe('—')
    expect(plain(formatValue(undefined, 'percent', 'RUB'))).toBe('—')
  })
})

describe('набор виджетов', () => {
  it('девять виджетов из задачи, у каждого свой идентификатор', () => {
    expect(WIDGETS.map((widget) => widget.title)).toEqual([
      'Заказы',
      'Продажи',
      'Прибыль',
      '% выкупа по кабинету',
      'Возвраты',
      'ДРР · от заказов',
      'ДРР · от продаж',
      'Логистика · доставка до клиента',
      'Логистика · возвраты от клиента',
    ])
    expect(new Set(WIDGETS.map((widget) => widget.id)).size).toBe(9)
    expect(isWidgetId('orders')).toBe(true)
    expect(isWidgetId('unknown')).toBe(false)
  })
})
