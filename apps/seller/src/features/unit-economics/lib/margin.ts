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
const THRESHOLD_PERCENT = 1

/**
 * Тон бейджа маржи. Считается здесь, а не в компоненте: арифметика
 * над денежными величинами в .tsx запрещена линтером (CLAUDE.md §10).
 *
 * Сравнение целочисленное, без промежуточной доли: `маржа / выручка
 * > 0.01` и `маржа * 100 > выручка` отвечают на один вопрос, но первое
 * заводит float из денежных величин, что §3 и ADR-004 запрещают вместе
 * с промежуточными значениями. Порог — граница, а границу целые числа
 * проводят точно.
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

  const scaled = marginMinor * 100

  if (scaled > revenueMinor * THRESHOLD_PERCENT) {
    return 'positive'
  }

  return scaled < -revenueMinor * THRESHOLD_PERCENT ? 'negative' : 'neutral'
}

export interface MarginBadge {
  tone: MarginTone
  /**
   * Знак обязателен, а не украшение: статус читается без цвета
   * (docs/patterns.md, «Данные и статусы») — дальтонизм и чёрно-белая
   * печать отчёта, который клиент несёт бухгалтеру.
   */
  sign: string
  /** Величина без знака: знак уже отдельно, иначе минусов было бы два. */
  magnitudeMinor: number
}

/**
 * Тон, знак и величина одним вызовом — а не тремя, которые вызывающий
 * код обязан не перепутать.
 *
 * Разделение их и было дефектом: знак брался из тона, а сумма
 * форматировалась исходная, со своим минусом. Убыток выходил
 * «− −500 ₽», а отрицательная нейтральная маржа — «= −500 ₽».
 * Собранные вместе, знак и величина разойтись уже не могут.
 */
export function marginBadge(
  marginMinor: number,
  revenueMinor: number,
): MarginBadge {
  const tone = marginTone(marginMinor, revenueMinor)

  return {
    tone,
    sign: marginMinor > 0 ? '+' : marginMinor < 0 ? '−' : '=',
    magnitudeMinor: Math.abs(marginMinor),
  }
}
