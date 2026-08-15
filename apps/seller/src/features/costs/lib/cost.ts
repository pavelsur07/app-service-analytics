/**
 * Разбор введённой человеком суммы в минорные единицы.
 *
 * Живёт здесь, а не в компоненте, и не на бэкенде: API принимает только
 * целые минорные единицы, потому что дробное число в JSON — это double,
 * и `420.10` приезжает как `420.09999999999997` (ADR-004 запрещает float
 * в денежных вычислениях). Значит превратить «420,10» в 42010 обязан
 * экран — и делать это через parseFloat нельзя по той же причине.
 *
 * Разбор строкой, а не арифметикой: дробная часть дополняется нулями
 * до двух знаков и приклеивается к целой. Ни одного умножения на 100.
 *
 * null означает «это не сумма» — пустая строка, буквы, три знака после
 * запятой. Подставлять ноль нельзя: ноль это тоже цена, и клиент,
 * опечатавшийся в поле, увидел бы «себестоимость 0» вместо отказа.
 */
export function parseAmountToMinor(input: string): number | null {
  const normalized = input.trim().replace(',', '.')
  if (normalized === '') {
    return null
  }

  const match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(normalized)
  if (match === null) {
    return null
  }

  const major = match[1] ?? '0'
  const minor = (match[2] ?? '').padEnd(2, '0')

  return Number(`${major}${minor}`)
}

/**
 * Сколько карточек ещё без цены. Отдельной функцией, потому что «из 62
 * задано 8» и «осталось 54» — разные числа, и путать их на экране
 * означало бы обещать клиенту не ту работу.
 */
export function listingsWithoutCost(
  listingCount: number,
  pricedCount: number,
): number {
  return Math.max(0, listingCount - pricedCount)
}

/**
 * Что затронет исправление: сколько дней и сколько проданных штук.
 *
 * ADR-013 требует показать оба числа до нажатия — исправление меняет
 * уже показанную прибыль, и «12 дней и 47 штук» это совсем другое
 * решение, чем «2 дня и 1 штука».
 *
 * Дни считаются включительно: цена, заведённая сегодня, действует
 * один день, а не ноль.
 */
export function correctionImpact(
  effectiveFrom: string,
  deliveredSinceCost: number,
  serverToday: string,
): { days: number; units: number } {
  // Обе даты приходят строками «ГГГГ-ММ-ДД» от сервера, посчитанные
  // по календарю площадки. Часы браузера здесь не участвуют: восточнее
  // Москвы локальная полночь наступает раньше серверной, и дни со
  // штуками оказались бы посчитаны за разные периоды.
  const from = Date.parse(`${effectiveFrom}T00:00:00Z`)
  const to = Date.parse(`${serverToday}T00:00:00Z`)

  const days =
    Number.isNaN(from) || Number.isNaN(to)
      ? 0
      : Math.max(1, Math.floor((to - from) / 86_400_000) + 1)

  return { days, units: deliveredSinceCost }
}
