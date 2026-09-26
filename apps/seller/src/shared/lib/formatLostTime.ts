const DAYS = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 })
const WHOLE = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 })

// Потерянное время ожидания: меньше суток — в часах («5 ч»), иначе в днях
// с одним знаком («20,3 дн»). Часы приходят целыми и округлёнными вверх,
// поэтому положительная потеря не выглядит нулём, а «0 ч» — честный ноль.
export function formatLostTime(hours: number | null | undefined): string {
  if (hours === null || hours === undefined) {
    return '—'
  }
  if (hours < 24) {
    return `${WHOLE.format(hours)} ч`
  }

  return `${DAYS.format(hours / 24)} дн`
}
