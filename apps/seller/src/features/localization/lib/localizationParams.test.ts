import { describe, expect, it } from 'vitest'

import {
  localizationSearchWithCursor,
  localizationSearchWithDays,
  localizationSearchFromDemandMatrix,
  localizationSearchWithView,
  parseLocalizationView,
} from './localizationParams'

describe('вкладка экрана локализации в адресе', () => {
  it('принимает только известные вкладки, иначе — спрос', () => {
    expect(parseLocalizationView('items')).toBe('items')
    expect(parseLocalizationView('clusters')).toBe('clusters')
    expect(parseLocalizationView('demand')).toBe('demand')
    expect(parseLocalizationView(null)).toBe('demand')
    expect(parseLocalizationView('')).toBe('demand')
    expect(parseLocalizationView('skus')).toBe('demand')
  })

  it('старый адрес матрицы спроса ведёт на вкладку спроса с тем же поиском', () => {
    const next = localizationSearchFromDemandMatrix(
      new URLSearchParams('search=кофе&cursor=demand-page'),
    )

    expect(next.get('view')).toBe('demand')
    expect(next.get('search')).toBe('кофе')
    expect(next.get('cursor')).toBe('demand-page')
  })

  it('уход со спроса сбрасывает его курсор и поиск, период остаётся', () => {
    const next = localizationSearchWithView(
      new URLSearchParams('view=demand&days=90&search=кофе&cursor=demand-page'),
      'clusters',
    )

    expect(next.get('view')).toBe('clusters')
    expect(next.get('days')).toBe('90')
    expect(next.has('cursor')).toBe(false)
    expect(next.has('search')).toBe(false)
  })

  it('переход на спрос сбрасывает курсор локализации', () => {
    // Адрес без view — это спрос по умолчанию; здесь явно кластеры.
    const next = localizationSearchWithView(
      new URLSearchParams('view=items&days=30&cursor=items-page'),
      'demand',
    )

    expect(next.get('view')).toBe('demand')
    expect(next.has('cursor')).toBe(false)
  })

  it('смена вкладки внутри локализации сохраняет период и курсор', () => {
    const next = localizationSearchWithView(
      new URLSearchParams('view=clusters&days=90&cursor=next-page'),
      'items',
    )

    expect(next.get('view')).toBe('items')
    expect(next.get('days')).toBe('90')
    expect(next.get('cursor')).toBe('next-page')
  })

  it('смена периода и страницы сохраняет вкладку', () => {
    const current = new URLSearchParams('days=30&view=items')

    expect(localizationSearchWithDays(current, 90).get('view')).toBe('items')
    expect(localizationSearchWithCursor(current, 'c').get('view')).toBe('items')
  })
})
