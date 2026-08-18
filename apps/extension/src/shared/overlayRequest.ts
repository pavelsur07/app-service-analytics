import type { SkuSalesSummaryResponse } from '../api/client'

/**
 * Разговор оверлея с service worker.
 *
 * Зачем через сообщение, а не запросом на месте: content-script
 * выполняется в origin страницы маркетплейса, и его fetch подчиняется
 * CORS — ozon.ru не разрешал бы обращаться к нашему API, и не должен.
 * Service worker расширения ходит по своим host_permissions, CORS
 * его не касается.
 *
 * Побочно так лучше: проверка «мой ли это товар», токен и хранилище
 * целиком живут в service worker, и content-script их не трогает вовсе.
 * На чужой странице чем меньше нашего кода знает о секретах, тем лучше.
 */

/** Что показать на карточке: цифры продаж и состояние отслеживания. */
export interface OverlayRequest {
  readonly type: 'conwix:overlay'
  readonly marketplaceSku: string
}

/**
 * Включить или выключить отслеживание артикула. Одно сообщение
 * с флагом, а не два типа: сервер отвечает на оба одинаково, и разводить
 * их означало бы писать один и тот же разбор дважды.
 */
export interface SetTrackingRequest {
  readonly type: 'conwix:set-tracking'
  readonly marketplaceSku: string
  readonly tracked: boolean
}

export interface OverlayData {
  readonly sales: SkuSalesSummaryResponse
  readonly tracked: boolean
  /**
   * Визит открыт service worker'ом ради снятия цены, а не человеком.
   * Признак приходит от него же, а не выводится из адреса: параметр
   * в URL пережил бы копирование ссылки, а редирект Ozon — нет.
   */
  readonly capture: boolean
}

/** Снятая с карточки витринная цена — от content-script к service worker. */
export interface ObservationMessage {
  readonly type: 'conwix:observation'
  readonly marketplaceSku: string
  readonly observedAt: string
  readonly amountMinor: number
  readonly currency: string
}

/**
 * Итог попытки изменить отслеживание. `error` — текст от сервера,
 * а не наш: он объясняет причину («нет активного подключения Ozon»,
 * «больше 50 артикулов»), и подменять его общим «не удалось» значило бы
 * прятать от продавца ровно то, что ему нужно знать.
 */
export interface TrackingResult {
  readonly tracked: boolean
  readonly error: string | null
}

export function overlayRequest(marketplaceSku: string): OverlayRequest {
  return { type: 'conwix:overlay', marketplaceSku }
}

export function setTrackingRequest(
  marketplaceSku: string,
  tracked: boolean,
): SetTrackingRequest {
  return { type: 'conwix:set-tracking', marketplaceSku, tracked }
}

export function observationMessage(
  marketplaceSku: string,
  observedAt: string,
  amountMinor: number,
  currency: string,
): ObservationMessage {
  return {
    type: 'conwix:observation',
    marketplaceSku,
    observedAt,
    amountMinor,
    currency,
  }
}

export function isObservationMessage(
  value: unknown,
): value is ObservationMessage {
  const candidate = asRecord(value)

  return (
    null !== candidate &&
    'conwix:observation' === candidate.type &&
    isNonEmptyString(candidate.marketplaceSku) &&
    isNonEmptyString(candidate.observedAt) &&
    'number' === typeof candidate.amountMinor &&
    Number.isInteger(candidate.amountMinor) &&
    isNonEmptyString(candidate.currency)
  )
}

export function isOverlayRequest(value: unknown): value is OverlayRequest {
  const candidate = asRecord(value)

  return (
    null !== candidate &&
    'conwix:overlay' === candidate.type &&
    isNonEmptyString(candidate.marketplaceSku)
  )
}

export function isSetTrackingRequest(
  value: unknown,
): value is SetTrackingRequest {
  const candidate = asRecord(value)

  return (
    null !== candidate &&
    'conwix:set-tracking' === candidate.type &&
    isNonEmptyString(candidate.marketplaceSku) &&
    'boolean' === typeof candidate.tracked
  )
}

/**
 * Ответ намеренно один и тот же для «не подключено», «не наш товар»
 * и «не смогли спросить»: оверлей во всех трёх случаях делает одно —
 * молчит. Различать их ему незачем, а знать про них — тем более.
 */
export function parseOverlayData(value: unknown): OverlayData | null {
  const candidate = asRecord(value)
  if (null === candidate) {
    return null
  }

  const sales = asRecord(candidate.sales)
  if (
    null === sales ||
    'string' !== typeof sales.marketplaceSku ||
    'number' !== typeof sales.days ||
    !Array.isArray(sales.totals) ||
    'boolean' !== typeof candidate.tracked ||
    'boolean' !== typeof candidate.capture
  ) {
    return null
  }

  return {
    sales: candidate.sales as SkuSalesSummaryResponse,
    tracked: candidate.tracked,
    capture: candidate.capture,
  }
}

export function parseTrackingResult(value: unknown): TrackingResult | null {
  const candidate = asRecord(value)
  if (
    null === candidate ||
    'boolean' !== typeof candidate.tracked ||
    !(null === candidate.error || 'string' === typeof candidate.error)
  ) {
    return null
  }

  return { tracked: candidate.tracked, error: candidate.error }
}

function asRecord(value: unknown): Record<string, unknown> | null {
  if (null === value || 'object' !== typeof value) {
    return null
  }

  return value as Record<string, unknown>
}

function isNonEmptyString(value: unknown): boolean {
  return 'string' === typeof value && '' !== value
}
