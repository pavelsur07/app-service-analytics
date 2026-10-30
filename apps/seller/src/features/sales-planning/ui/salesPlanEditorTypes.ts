export interface PickedCell {
  marketplaceSku: string
  date: string
  quantity: number
}

/**
 * Снимок дня на момент открытия формы — вместе с версией плана.
 * Версия берётся отсюда, а не из перечитанного плана: иначе фоновое
 * перечитывание после чужой правки подменило бы её, и сохранение
 * молча затёрло бы чужое значение вместо конфликта (ADR-008).
 */
export interface PickedDay extends PickedCell {
  version: number
}
