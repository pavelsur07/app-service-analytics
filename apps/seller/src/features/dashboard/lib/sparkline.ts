// Геометрия спарклайна: ряд значений в точки SVG-ломаной в поле
// 100 × 32 (viewBox макета). Это масштабирование в координаты, а не
// денежный расчёт: суммы в подписях приходят с бэкенда, здесь только
// где поставить точку.

const WIDTH = 100
const TOP = 4
const BOTTOM = 28

export interface SparklineScale {
  low: number
  high: number
}

/** Общая шкала обоих рядов — иначе пунктир сравнения сравнивать не с чем. */
export function sharedScale(
  ...series: readonly (readonly number[])[]
): SparklineScale {
  const values = series.flat()
  if (values.length === 0) {
    return { low: 0, high: 0 }
  }

  return { low: Math.min(...values), high: Math.max(...values) }
}

/**
 * Точки `x,y` через пробел. Один день — точка посередине; ровный ряд —
 * линия по нижнему краю, а не деление на ноль.
 */
export function sparklinePoints(
  values: readonly number[],
  scale: SparklineScale,
): string {
  const span = scale.high - scale.low
  const last = values.length - 1

  return values
    .map((value, index) => {
      const x = last === 0 ? WIDTH / 2 : (index / last) * WIDTH
      const share = span === 0 ? 0 : (value - scale.low) / span
      const y = BOTTOM - share * (BOTTOM - TOP)

      return `${x.toFixed(2)},${y.toFixed(2)}`
    })
    .join(' ')
}

/** Подпись для чтения с экрана: куда ряд пришёл к концу периода. */
export function sparklineTrend(
  values: readonly number[],
): 'вырос' | 'снизился' | 'не изменился' {
  const first = values[0]
  const last = values.at(-1)
  if (first === undefined || last === undefined || first === last) {
    return 'не изменился'
  }

  return last > first ? 'вырос' : 'снизился'
}
