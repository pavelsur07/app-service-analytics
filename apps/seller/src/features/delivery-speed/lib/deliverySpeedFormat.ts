import type { components } from '../../../api/schema'

type Bucket = components['schemas']['DeliverySpeedBucketResponse']

export const NO_DATA = '—'
const DAYS = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 })
const WHOLE = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 })
const SECONDS_IN_DAY = 86_400

// Длительность — не деньги: перевод секунд в дни здесь только формат.
// Меньше суток — в часах, иначе в днях с одним знаком: «18 ч», «2,2 дн».
export function formatDuration(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) {
    return NO_DATA
  }
  if (seconds < SECONDS_IN_DAY) {
    return `${WHOLE.format(seconds / 3600)} ч`
  }

  return `${DAYS.format(seconds / SECONDS_IN_DAY)} дн`
}

// Потерянное время ожидания: меньше суток — в часах («5 ч»), иначе в днях
// с одним знаком («20,3 дн»). Малая, но положительная потеря не должна
// выглядеть нулём — по ней отсортирован список.
export function formatLostTime(hours: number | null | undefined): string {
  if (hours === null || hours === undefined) {
    return NO_DATA
  }
  if (hours < 24) {
    return `${WHOLE.format(hours)} ч`
  }

  return `${DAYS.format(hours / 24)} дн`
}

export function formatBucket(
  bucket: Pick<Bucket, 'minDays' | 'maxDays'>,
): string {
  return bucket.maxDays === null || bucket.maxDays === undefined
    ? `${bucket.minDays}+ дн`
    : `${bucket.minDays}–${bucket.maxDays - 1} дн`
}
