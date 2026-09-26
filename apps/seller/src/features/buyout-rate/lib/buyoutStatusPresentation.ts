import type { components } from '../../../api/schema'
import { formatBasisPoints } from '../../../shared/lib/formatBasisPoints'

const NO_DATA = 'Недостаточно данных'
type MaturityStatus =
  components['schemas']['BuyoutRateItemResponse']['maturityStatus']

export function formatRateBps(basisPoints: number | null | undefined): string {
  if (basisPoints === null || basisPoints === undefined) {
    return NO_DATA
  }

  return formatBasisPoints(basisPoints)
}

/**
 * ADR-031: прогноз построен без штук без оценки. Подпись показывается,
 * только когда прогноз есть и часть заказов в него не вошла.
 */
export function forecastCoverageLabel(
  projectedRateBps: number | null | undefined,
  unestimatedRateBps: number | null | undefined,
): string | null {
  if (
    projectedRateBps === null ||
    projectedRateBps === undefined ||
    unestimatedRateBps === null ||
    unestimatedRateBps === undefined ||
    unestimatedRateBps <= 0
  ) {
    return null
  }

  return `по ${formatRateBps(10000 - unestimatedRateBps)} заказов`
}

export function maturityPresentation(
  status: MaturityStatus,
  resolutionRateBps: number | null,
): { label: string; tone: 'neutral' | 'warning' } {
  switch (status) {
    case 'mature':
      return { label: 'Когорта созрела', tone: 'neutral' }
    case 'preliminary':
      return {
        label: `Предварительно · ${formatRateBps(resolutionRateBps)} разрешилось`,
        tone: 'warning',
      }
    default:
      return assertNever(status)
  }
}

function assertNever(value: never): never {
  throw new Error(`Неизвестный статус зрелости: ${String(value)}`)
}
