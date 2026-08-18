import { describe, expect, it } from 'vitest'

import {
  isObservationMessage,
  isOverlayRequest,
  isSetTrackingRequest,
  observationMessage,
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

  it('разбирает ответ с продажами, отслеживанием и признаком фонового визита', () => {
    const parsed = parseOverlayData({
      sales: { marketplaceSku: '123456789', days: 30, totals: [] },
      tracked: true,
      capture: false,
    })

    expect(parsed?.tracked).toBe(true)
    expect(parsed?.capture).toBe(false)
    expect(parsed?.sales.marketplaceSku).toBe('123456789')
  })

  it('отвергает ответ без признака фонового визита', () => {
    // Без флага content-script не знает, слать ли наблюдение. Считать
    // визит обычным по умолчанию нельзя: тогда фоновый обход молча
    // не собирал бы ничего, и выглядело бы это как «цены не меняются».
    expect(
      parseOverlayData({
        sales: { marketplaceSku: '1', days: 30, totals: [] },
        tracked: true,
      }),
    ).toBeNull()
  })

  it('отвергает ответ без состояния отслеживания', () => {
    // Без флага кнопка не знает, что показывать, а показать наугад
    // хуже, чем не показать: продавец решит, что отслеживание включено.
    expect(
      parseOverlayData({
        sales: { marketplaceSku: '1', days: 30, totals: [] },
        capture: false,
      }),
    ).toBeNull()
    expect(parseOverlayData({ tracked: true, capture: false })).toBeNull()
    expect(parseOverlayData(null)).toBeNull()
  })

  it('узнаёт сообщение с наблюдением и отвергает дробную сумму', () => {
    expect(
      isObservationMessage(
        observationMessage(
          '123456789',
          '2026-08-18T09:00:00.000Z',
          111_700,
          'RUB',
        ),
      ),
    ).toBe(true)

    // Минорные единицы целые всегда: дробное число здесь означало бы,
    // что где-то по дороге появился float (ADR-004).
    expect(
      isObservationMessage({
        type: 'conwix:observation',
        marketplaceSku: '1',
        observedAt: '2026-08-18T09:00:00.000Z',
        amountMinor: 1117.5,
        currency: 'RUB',
      }),
    ).toBe(false)
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
