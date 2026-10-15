// Копейки (минорные единицы) в отображаемую сумму. Компонент не считает
// деньги — только форматирует то, что посчитал бэкенд (patterns.md).
//
// `wholeUnits` — крупная сумма целыми рублями (главная): округляет только
// показ, через Intl, — сумма, посчитанная бэкендом, не меняется.
export function formatMinorAmount(
  minorAmount: number,
  currency: string,
  options: { wholeUnits?: boolean } = {},
): string {
  const digits = options.wholeUnits === true ? 0 : undefined

  return new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency,
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  }).format(minorAmount / 100)
}
