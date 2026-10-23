import { formatBasisPoints } from '../../../shared/lib/formatBasisPoints'

const NO_CHANGE_BPS = 10000

// Тренд приходит отношением спроса 7 дней к 28 в базисных пунктах,
// 10000 — без изменений; показываем изменение: «+60%», «−20%», «0%».
// null — заказов меньше порога, тренд — шум и не показывается.
export function formatTrend(trendBps: number | null | undefined): string {
  if (trendBps === null || trendBps === undefined) {
    return '—'
  }
  const change = trendBps - NO_CHANGE_BPS

  return `${change > 0 ? '+' : ''}${formatBasisPoints(change)}`
}

export type TrendTone = 'up' | 'down' | 'flat' | 'none'

// Изменение в пределах ±10% — «без изменений»: на недельном окне
// меньшее колебание неотличимо от случайного.
const FLAT_BAND_BPS = 1000

export function trendTone(trendBps: number | null | undefined): TrendTone {
  if (trendBps === null || trendBps === undefined) {
    return 'none'
  }
  if (trendBps >= NO_CHANGE_BPS + FLAT_BAND_BPS) {
    return 'up'
  }
  if (trendBps <= NO_CHANGE_BPS - FLAT_BAND_BPS) {
    return 'down'
  }

  return 'flat'
}

export function formatShare(shareBps: number | null | undefined): string {
  return shareBps === null || shareBps === undefined
    ? '—'
    : formatBasisPoints(shareBps)
}

// Ступень фона ячейки по доле кластера в спросе SKU: 0 — без заливки.
export type HeatLevel = 0 | 1 | 2 | 3

export function heatLevel(shareBps: number | null | undefined): HeatLevel {
  if (shareBps === null || shareBps === undefined || shareBps < 1000) {
    return 0
  }
  if (shareBps < 2500) {
    return 1
  }
  if (shareBps < 5000) {
    return 2
  }

  return 3
}

// Подсказка ячейки: откуда цифра и почему ей можно не верить.
export function cellTitle(cell: {
  orders7: number
  orders14: number
  orders28: number
  trendBps?: number | null
  stockoutDays: number
}): string {
  const lines = [
    `Заказы: 7 дн — ${cell.orders7}, 14 дн — ${cell.orders14}, 28 дн — ${cell.orders28}`,
    `Тренд 7 к 28: ${formatTrend(cell.trendBps)}`,
  ]
  if (cell.stockoutDays > 0) {
    lines.push(`Без товара в кластере ${cell.stockoutDays} дн — спрос занижен`)
  }

  return lines.join('\n')
}
