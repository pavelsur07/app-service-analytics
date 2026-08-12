import type { SkuSalesSummaryResponse } from '../api/client'

/**
 * Запрос итога продаж от content-script к service worker.
 *
 * Зачем через сообщение, а не запросом на месте: content-script
 * выполняется в origin страницы маркетплейса, и его fetch подчиняется
 * CORS — ozon.ru не разрешал бы обращаться к нашему API, и не должен.
 * Service worker расширения ходит по своим host_permissions, CORS
 * его не касается.
 *
 * Побочно так лучше: проверка «мой ли товар» целиком уезжает
 * в service worker, и content-script перестаёт трогать хранилище
 * и токен вовсе. На чужой странице чем меньше нашего кода знает
 * о секретах, тем лучше.
 */
export interface SalesRequest {
  readonly type: 'conwix:sales'
  readonly marketplaceSku: string
}

export function salesRequest(marketplaceSku: string): SalesRequest {
  return { type: 'conwix:sales', marketplaceSku }
}

export function isSalesRequest(value: unknown): value is SalesRequest {
  if (null === value || 'object' !== typeof value) {
    return false
  }

  const candidate = value as Record<string, unknown>

  return (
    'conwix:sales' === candidate.type &&
    'string' === typeof candidate.marketplaceSku &&
    '' !== candidate.marketplaceSku
  )
}

/**
 * Ответ намеренно один и тот же для «не подключено», «не наш товар»
 * и «не смогли спросить»: content-script во всех трёх случаях делает
 * одно — молчит. Различать их ему незачем, а знать про них — тем более.
 */
export function parseSalesResponse(
  value: unknown,
): SkuSalesSummaryResponse | null {
  if (null === value || 'object' !== typeof value) {
    return null
  }

  const candidate = value as Record<string, unknown>

  return 'string' === typeof candidate.marketplaceSku &&
    'number' === typeof candidate.days &&
    Array.isArray(candidate.totals)
    ? (value as SkuSalesSummaryResponse)
    : null
}
