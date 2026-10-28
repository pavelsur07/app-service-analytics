// Feature-local fixed geometry, matching h-16 rows and the h-96 viewport.
export const CALENDAR_ROW_HEIGHT = 64
const VISIBLE_ROWS = 6
const OVERSCAN = 2

export function calendarWindow(count: number, scrollTop: number) {
  if (count <= 200) return { start: 0, end: count, top: 0, bottom: 0 }
  const first = Math.min(
    Math.max(0, count - VISIBLE_ROWS),
    Math.max(0, Math.floor(scrollTop / CALENDAR_ROW_HEIGHT)),
  )
  const start = Math.max(0, first - OVERSCAN)
  const end = Math.min(count, first + VISIBLE_ROWS + OVERSCAN)
  return {
    start,
    end,
    top: start * CALENDAR_ROW_HEIGHT,
    bottom: (count - end) * CALENDAR_ROW_HEIGHT,
  }
}

export function calendarKeyboardRow(
  key: string,
  current: number,
  count: number,
): number | null {
  if (count === 0) return null
  const moves: Record<string, number> = {
    ArrowUp: current - 1,
    ArrowDown: current + 1,
    PageUp: current - VISIBLE_ROWS,
    PageDown: current + VISIBLE_ROWS,
    Home: 0,
    End: count - 1,
  }
  const next = moves[key]
  return next === undefined ? null : Math.max(0, Math.min(count - 1, next))
}
