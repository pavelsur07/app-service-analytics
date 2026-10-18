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
