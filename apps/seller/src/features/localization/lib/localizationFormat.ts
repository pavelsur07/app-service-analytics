import type { components } from '../../../api/schema'
import { formatBasisPoints } from '../../../shared/lib/formatBasisPoints'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'

type SourceCluster = components['schemas']['LocalizationSourceClusterResponse']

export const NO_DATA = '—'
const QUANTITY = new Intl.NumberFormat('ru-RU')

export function formatQuantity(quantity: number): string {
  return `${QUANTITY.format(quantity)} шт`
}

// null — мало данных или логистика ещё не начислена: показывается прочерк,
// а не ноль, чтобы отсутствие данных не читалось как «бесплатно».
export function formatShare(basisPoints: number | null | undefined): string {
  return basisPoints === null || basisPoints === undefined
    ? NO_DATA
    : formatBasisPoints(basisPoints)
}

export function formatPerUnit(
  minorAmount: number | null | undefined,
  currency: string | null | undefined,
): string {
  if (
    minorAmount === null ||
    minorAmount === undefined ||
    currency === null ||
    currency === undefined
  ) {
    return NO_DATA
  }

  return `${formatMinorAmount(minorAmount, currency)}/шт`
}

// «Москва 60%, Омск 40%»; без доли — «Москва 2 шт»: у малого кластера
// доля скрыта бэкендом, штуки остаются правдой.
export function formatSources(sources: readonly SourceCluster[]): string {
  if (sources.length === 0) {
    return NO_DATA
  }

  return sources
    .map((source) =>
      source.shareBps === null || source.shareBps === undefined
        ? `${source.cluster} ${formatQuantity(source.quantity)}`
        : `${source.cluster} ${formatBasisPoints(source.shareBps)}`,
    )
    .join(', ')
}
