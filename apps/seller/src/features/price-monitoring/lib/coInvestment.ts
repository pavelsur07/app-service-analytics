/**
 * Представление соинвеста на экране. Чистые функции: арифметика над
 * денежными величинами в компонентах запрещена (CLAUDE.md §10), и здесь
 * её нет — разницу считает сервер, а эти функции только выбирают,
 * что показать.
 */

export interface CoInvestmentView {
  /** Доля от цены кабинета, целыми процентами. null — считать не из чего. */
  readonly percent: number | null
  /**
   * Отрицательный соинвест: витрина выше цены кабинета. Означает, что
   * прочитали не тот узел страницы либо цену подняли между выгрузками.
   * Поджимать к нулю нельзя — так поломка парсера стала бы невидимой.
   */
  readonly suspicious: boolean
}

export function coInvestmentView(
  coInvestmentMinor: number | null | undefined,
  sellerPriceMinor: number | null | undefined,
): CoInvestmentView {
  if (
    null === coInvestmentMinor ||
    undefined === coInvestmentMinor ||
    null === sellerPriceMinor ||
    undefined === sellerPriceMinor ||
    0 === sellerPriceMinor
  ) {
    return { percent: null, suspicious: false }
  }

  return {
    // Процент — не деньги: доля целыми, без копеек и без округления
    // денежных величин на клиенте.
    percent: Math.round((coInvestmentMinor * 100) / sellerPriceMinor),
    suspicious: coInvestmentMinor < 0,
  }
}

/**
 * Возраст наблюдения словами. Наблюдения идут, только пока у продавца
 * запущен браузер с расширением (ADR-014), поэтому экран обязан
 * показывать, когда снимали, а не подразумевать непрерывность.
 */
export function observedAgo(
  observedAt: string | null | undefined,
  now: Date,
): string {
  if (null === observedAt || undefined === observedAt) {
    return 'ещё не снимали'
  }

  const minutes = Math.floor(
    (now.getTime() - new Date(observedAt).getTime()) / 60_000,
  )
  if (minutes < 1) {
    return 'только что'
  }
  if (minutes < 60) {
    return `${minutes} мин назад`
  }

  const hours = Math.floor(minutes / 60)
  if (hours < 24) {
    return `${hours} ч назад`
  }

  return `${Math.floor(hours / 24)} дн назад`
}
