// Basis points (10000 = 100%) в процент для показа: «45,46%», «50%».
// Только форматирование того, что посчитал бэкенд, — без арифметики
// над долями (patterns.md). Целочисленно, без float-округления.
export function formatBasisPoints(basisPoints: number): string {
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
