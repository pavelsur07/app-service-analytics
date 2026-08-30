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
