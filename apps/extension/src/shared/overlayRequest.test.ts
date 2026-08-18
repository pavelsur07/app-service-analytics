import { describe, expect, it } from 'vitest'

import {
  isOverlayRequest,
  isSetTrackingRequest,
  overlayRequest,
  parseOverlayData,
  parseTrackingResult,
  setTrackingRequest,
} from './overlayRequest'

describe('разбор сообщений оверлея', () => {
  it('узнаёт свои сообщения и не узнаёт чужие', () => {
    expect(isOverlayRequest(overlayRequest('123456789'))).toBe(true)
    expect(isSetTrackingRequest(setTrackingRequest('123456789', true))).toBe(
      true,
    )

    // Слушателей в service worker несколько, и ответивший первым
    // закрывает канал: узнать чужое сообщение — значит сломать
    // чужой обработчик.
    expect(isOverlayRequest(setTrackingRequest('123456789', true))).toBe(false)
    expect(isSetTrackingRequest(overlayRequest('123456789'))).toBe(false)
    expect(isOverlayRequest({ type: 'conwix:overlay' })).toBe(false)
    expect(isOverlayRequest(null)).toBe(false)
    expect(
      isSetTrackingRequest({
        type: 'conwix:set-tracking',
        marketplaceSku: '1',
      }),
    ).toBe(false)
  })

  it('разбирает ответ с продажами и состоянием отслеживания', () => {
    const parsed = parseOverlayData({
      sales: { marketplaceSku: '123456789', days: 30, totals: [] },
      tracked: true,
    })

    expect(parsed?.tracked).toBe(true)
    expect(parsed?.sales.marketplaceSku).toBe('123456789')
  })

  it('отвергает ответ без состояния отслеживания', () => {
    // Без флага кнопка не знает, что показывать, а показать наугад
    // хуже, чем не показать: продавец решит, что отслеживание включено.
    expect(
      parseOverlayData({
        sales: { marketplaceSku: '1', days: 30, totals: [] },
      }),
    ).toBeNull()
    expect(parseOverlayData({ tracked: true })).toBeNull()
    expect(parseOverlayData(null)).toBeNull()
  })

  it('разбирает итог переключения вместе с текстом отказа', () => {
    expect(
      parseTrackingResult({ tracked: false, error: 'Больше 50 нельзя' }),
    ).toEqual({ tracked: false, error: 'Больше 50 нельзя' })
    expect(parseTrackingResult({ tracked: true, error: null })).toEqual({
      tracked: true,
      error: null,
    })
    expect(parseTrackingResult({ tracked: true })).toBeNull()
  })
})
