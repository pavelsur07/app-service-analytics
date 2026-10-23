const RATE = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 })

// Спрос приходит в тысячных долях штуки в день — целым, без float
// на сервере. Деление здесь только формат: «1,5 шт/дн». Положительный
// спрос меньше видимого знака — «<0,1», чтобы одна продажа за окно
// не выглядела как позиция без продаж.
const MIN_VISIBLE_MILLI = 50

export function formatDemand(milliPerDay: number): string {
  if (milliPerDay > 0 && milliPerDay < MIN_VISIBLE_MILLI) {
    return `<${RATE.format(0.1)} шт/дн`
  }

  return `${RATE.format(milliPerDay / 1000)} шт/дн`
}
