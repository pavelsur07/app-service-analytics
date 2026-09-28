/** Вкладка экрана «Юнит-экономика» — параметр адреса `view`. */
export type UnitEconomicsTab = 'units' | 'reconciliation'

export const TABS: { tab: UnitEconomicsTab; label: string }[] = [
  { tab: 'units', label: 'Юнит-экономика' },
  { tab: 'reconciliation', label: 'Сверка' },
]

/** Неизвестное значение — первая вкладка, а не ошибка: ссылка открывает экран. */
export function parseTab(value: string | null): UnitEconomicsTab {
  return value === 'reconciliation' ? 'reconciliation' : 'units'
}

/**
 * Адрес с другой вкладкой. Остальные параметры сохраняются: вернувшись
 * на юнит-экономику, клиент видит то же окно и тот же порядок.
 * У первой вкладки параметра нет — ссылка на экран остаётся прежней.
 */
export function searchWithTab(
  search: URLSearchParams,
  tab: UnitEconomicsTab,
): URLSearchParams {
  const next = new URLSearchParams(search)
  if (tab === 'units') {
    next.delete('view')
  } else {
    next.set('view', tab)
  }

  return next
}
