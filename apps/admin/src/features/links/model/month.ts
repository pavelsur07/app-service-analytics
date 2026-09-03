function toWireMonth(date: Date): string {
  return `${String(date.getUTCFullYear()).padStart(4, '0')}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`
}

export function currentUtcMonth(now: Date = new Date()): string {
  return toWireMonth(now)
}

export function shiftMonth(month: string, delta: -1 | 1): string {
  const date = new Date(`${month}-01T00:00:00Z`)
  date.setUTCMonth(date.getUTCMonth() + delta)

  return toWireMonth(date)
}

export function isCurrentMonth(month: string, now: Date = new Date()): boolean {
  return month === currentUtcMonth(now)
}

export function formatMonthLabel(month: string): string {
  const date = new Date(`${month}-01T00:00:00Z`)
  const name = new Intl.DateTimeFormat('ru-RU', {
    month: 'long',
    timeZone: 'UTC',
  }).format(date)

  return `${name} ${String(date.getUTCFullYear())}`
}
