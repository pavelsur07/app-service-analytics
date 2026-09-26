import { describe, expect, it } from 'vitest'

import {
  localizationSearchWithCursor,
  localizationSearchWithDays,
  localizationSearchWithView,
  parseLocalizationView,
} from './localizationParams'

describe('вкладка экрана локализации в адресе', () => {
  it('принимает только известные вкладки, иначе — кластеры', () => {
    expect(parseLocalizationView('items')).toBe('items')
    expect(parseLocalizationView('clusters')).toBe('clusters')
    expect(parseLocalizationView(null)).toBe('clusters')
    expect(parseLocalizationView('')).toBe('clusters')
    expect(parseLocalizationView('skus')).toBe('clusters')
  })

  it('смена вкладки сохраняет период и курсор', () => {
    const next = localizationSearchWithView(
      new URLSearchParams('days=90&cursor=next-page'),
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
