// Only presentation: the server owns the amount and currency scale.
// Intl receives BigInt whole units and a placement template, never the minor amount.
export function formatMinorAmountExact(
  minor: string,
  currency: string,
  fractionDigits: number,
): string {
  if (
    typeof minor !== 'string' ||
    !/^(?:0|-[1-9][0-9]*|[1-9][0-9]*)$/.test(minor) ||
    typeof currency !== 'string' ||
    currency.trim() === '' ||
    !Number.isInteger(fractionDigits) ||
    fractionDigits < 0 ||
    fractionDigits > 4
  )
    return '—'

  const negative = minor.startsWith('-')
  const digits = (negative ? minor.slice(1) : minor).padStart(
    fractionDigits + 1,
    '0',
  )
  const whole = fractionDigits === 0 ? digits : digits.slice(0, -fractionDigits)
  const fraction = fractionDigits === 0 ? '' : digits.slice(-fractionDigits)
  const grouped = new Intl.NumberFormat('ru-RU', {
    maximumFractionDigits: 0,
  }).format(BigInt(whole))
  const options = {
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }
  let formatter: Intl.NumberFormat
  let suffix = ''
  try {
    formatter = new Intl.NumberFormat('ru-RU', {
      ...options,
      style: 'currency',
      currency,
    })
  } catch {
    // An unfamiliar client currency must not change the server's scale or digits.
    formatter = new Intl.NumberFormat('ru-RU', options)
    suffix = `\u00a0${currency}`
  }
  // A signed nonzero template preserves '-' even when the exact whole part is 0.
  return (
    formatter
      .formatToParts(negative ? -1n : 1n)
      .map((part) => {
        if (part.type === 'integer') return grouped
        if (part.type === 'fraction') return fraction
        return part.value
      })
      .join('') + suffix
  )
}
