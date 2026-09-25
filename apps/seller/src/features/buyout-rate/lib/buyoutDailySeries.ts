interface DailyRates {
  actualBuyoutRateBps?: number | null
  projectedBuyoutRateBps?: number | null
}

export function countAvailableRateDays(series: readonly DailyRates[]): {
  actualDays: number
  projectedDays: number
} {
  let actualDays = 0
  let projectedDays = 0

  for (const point of series) {
    if (
      point.actualBuyoutRateBps !== null &&
      point.actualBuyoutRateBps !== undefined
    ) {
      actualDays += 1
    }
    if (
      point.projectedBuyoutRateBps !== null &&
      point.projectedBuyoutRateBps !== undefined
    ) {
      projectedDays += 1
    }
  }

  return { actualDays, projectedDays }
}

interface DailyMaturity {
  maturityStatus: 'mature' | 'preliminary'
}

/**
 * Непрерывные отрезки незрелых точек в индексах ряда. Зрелость считается
 * по каждому дню отдельно (ADR-029), поэтому отрезков может быть несколько.
 */
export function preliminaryRanges(
  series: readonly DailyMaturity[],
): { start: number; end: number }[] {
  const ranges: { start: number; end: number }[] = []
  let start: number | null = null

  series.forEach((point, index) => {
    if (point.maturityStatus === 'preliminary') {
      start ??= index
      return
    }
    if (start !== null) {
      ranges.push({ start, end: index - 1 })
      start = null
    }
  })
  if (start !== null) {
    ranges.push({ start, end: series.length - 1 })
  }

  return ranges
}
