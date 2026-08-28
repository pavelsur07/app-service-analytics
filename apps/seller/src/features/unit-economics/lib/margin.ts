// Доля, которую расход съедает от выручки. Чистая функция: считать
// её в компоненте значило бы переписывать при каждом изменении разметки.
//
// Деление на ноль — не ошибка, а обычный случай: у товара за период
// бывают расходы без выручки (возврат обработан в этом периоде,
// а продан товар в прошлом). Показывать в этом случае нечего.
export function shareOfRevenue(
  amountMinor: number,
  revenueMinor: number,
): number | null {
  if (revenueMinor <= 0) {
    return null
  }

  return Math.abs(amountMinor) / revenueMinor
}

// Расходы приходят от площадки отрицательными, и это часть данных.
// Для человека знак минуса перед словом «логистика» лишний — величина
// уже названа расходом, — а вот у итога знак значим: он бывает
// отрицательным, и это главное, что должно броситься в глаза.
export function isLoss(marginMinor: number): boolean {
  return marginMinor < 0
}

export type MarginTone = 'positive' | 'negative' | 'neutral'

// Порог ±1% от выручки: маржа в полпроцента — это не «заработали»
// и не «потеряли», а шум, и красить её в зелёный значит обещать
// прибыль там, где её нет.
const THRESHOLD = 0.01

/**
 * Тон бейджа маржи. Считается здесь, а не в компоненте: арифметика
 * над денежными величинами в .tsx запрещена линтером (CLAUDE.md §10).
 *
 * Нулевая выручка не делает строку нейтральной сама по себе. У товара
 * за период бывают расходы без продаж — возврат обработан сейчас,
 * а продан товар был раньше, — и это худшая строка на экране, а не
 * серая. Доли от выручки тут нет, поэтому решает знак самой маржи.
 */
export function marginTone(
  marginMinor: number,
  revenueMinor: number,
): MarginTone {
  if (revenueMinor <= 0) {
    return marginMinor === 0
      ? 'neutral'
      : marginMinor < 0
        ? 'negative'
        : 'positive'
  }

  const share = marginMinor / revenueMinor

  if (share > THRESHOLD) {
    return 'positive'
  }

  return share < -THRESHOLD ? 'negative' : 'neutral'
}

/**
 * Знак перед суммой маржи. Обязателен, а не украшение: статус читается
 * без цвета (docs/patterns.md, «Данные и статусы») — дальтонизм
 * и чёрно-белая печать отчёта, который клиент несёт бухгалтеру.
 */
export function marginSign(tone: MarginTone): string {
  return tone === 'positive' ? '+' : tone === 'negative' ? '−' : '='
}
