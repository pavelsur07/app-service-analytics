import type { components } from '../../../api/schema'

const NO_DATA = 'Недостаточно данных'
type MaturityStatus =
  components['schemas']['BuyoutRateItemResponse']['maturityStatus']

export function formatRateBps(basisPoints: number | null | undefined): string {
  if (basisPoints === null || basisPoints === undefined) {
    return NO_DATA
  }

  const integer = Math.trunc(basisPoints)
  const absolute = Math.abs(integer)
  const whole = Math.floor(absolute / 100)
  const remainder = absolute % 100
  const fraction =
    remainder === 0
      ? ''
      : `,${String(remainder).padStart(2, '0').replace(/0$/, '')}`

  return `${integer < 0 ? '−' : ''}${whole}${fraction}%`
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
