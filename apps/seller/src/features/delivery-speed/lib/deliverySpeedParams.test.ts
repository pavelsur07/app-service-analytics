import { describe, expect, it } from 'vitest'

import {
  deliverySpeedSearchWithCursor,
  deliverySpeedSearchWithDays,
  deliverySpeedSearchWithView,
  parseDeliverySpeedView,
} from './deliverySpeedParams'

describe('вкладка экрана доставки в адресе', () => {
  it('принимает только известные вкладки, иначе — кластеры', () => {
    expect(parseDeliverySpeedView('items')).toBe('items')
    expect(parseDeliverySpeedView('buyout')).toBe('buyout')
    expect(parseDeliverySpeedView('routes')).toBe('routes')
    expect(parseDeliverySpeedView('clusters')).toBe('clusters')
    expect(parseDeliverySpeedView(null)).toBe('clusters')
    expect(parseDeliverySpeedView('')).toBe('clusters')
    expect(parseDeliverySpeedView('skus')).toBe('clusters')
  })

  it('смена вкладки сохраняет период и курсор', () => {
    const next = deliverySpeedSearchWithView(
      new URLSearchParams('days=90&cursor=next-page'),
      'routes',
    )

    expect(next.get('view')).toBe('routes')
    expect(next.get('days')).toBe('90')
    expect(next.get('cursor')).toBe('next-page')
  })

  it('смена периода и страницы сохраняет вкладку', () => {
    const current = new URLSearchParams('days=30&view=items')

    expect(deliverySpeedSearchWithDays(current, 90).get('view')).toBe('items')
    expect(deliverySpeedSearchWithCursor(current, 'c').get('view')).toBe(
      'items',
    )
  })
})
