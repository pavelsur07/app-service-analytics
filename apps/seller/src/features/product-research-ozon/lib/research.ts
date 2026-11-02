import { ApiError } from '../../../api/ApiError'
import type {
  Preview,
  ProjectMonth,
  ProjectProgress,
  StartInput,
} from '../model/types'

export function loadRequired(
  state: ProjectProgress | undefined,
  preview: Preview | null | undefined,
): boolean {
  return (
    preview?.months.some((target) => {
      if (target.partial) return true
      const saved = state?.months.find((m) => m.period.month === target.month)
      return (
        saved?.status !== 'ready' ||
        saved.period.dateFrom !== target.dateFrom ||
        saved.period.dateTo !== target.dateTo ||
        saved.period.partial !== target.partial ||
        saved.period.days !== target.days
      )
    }) ?? false
  )
}

export function categoryCanSearch(text: string): boolean {
  return text.length <= 200 && (text.match(/\p{L}/gu)?.length ?? 0) >= 3
}

export function requestId(
  now = Date.now(),
  random = crypto.getRandomValues(new Uint8Array(16)),
): string {
  const bytes = random.slice()
  let time = BigInt(now)
  for (let index = 5; index >= 0; index--) {
    bytes[index] = Number(time & 255n)
    time >>= 8n
  }
  bytes[6] = ((bytes[6] ?? 0) & 15) | 112
  bytes[8] = ((bytes[8] ?? 0) & 63) | 128
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

export const POLL_WINDOW = 10 * 60_000
export function pollInterval(
  status: ProjectProgress['status'] | undefined,
  failed: boolean,
  now: number,
  deadline: number,
): number | false {
  return status === 'loading' && !failed && now < deadline ? 5000 : false
}

export function projectStatus(status: ProjectProgress['status']): string {
  return {
    draft: 'Черновик',
    loading: 'Загрузка',
    loaded: 'Загружено',
    partially_loaded: 'Загружено частично',
    error: 'Ошибка',
  }[status]
}

export function monthStatus(status: ProjectMonth['status']): string {
  return {
    missing: 'Не загружен',
    queued: 'Ожидает',
    running: 'Загружается',
    ready: 'Готово',
    error: 'Ошибка',
  }[status]
}

export function researchFailure(code: string | null | undefined): string {
  switch (code) {
    case 'credentials_rejected':
      return 'Проверьте токен MPStats: сервис отклонил доступ.'
    case 'retry_exhausted':
      return 'Сервис не ответил после повторных попыток. Месяц можно загрузить повторно.'
    case 'incomplete_snapshot':
      return 'Не удалось получить полный срез месяца. Повторите загрузку.'
    case 'deadline_exceeded':
      return 'Истёк срок ожидания отчёта. Повторите загрузку месяца.'
    case 'catalog_not_ready':
      return 'Справочник категорий обновляется. Повторите поиск позже.'
    case 'version_conflict':
      return 'Проект изменился в другой вкладке. Обновите данные и повторите действие.'
    case 'preview_outdated':
      return 'Изменилась календарная дата. Повторите предпросмотр.'
    case 'no_months_to_load':
      return 'Все выбранные закрытые месяцы уже загружены.'
    case 'feature_disabled':
      return 'Подбор товаров Ozon пока недоступен.'
    case 'unavailable_month':
      return 'Будущий месяц недоступен. Текущий месяц требует явного согласия.'
    case 'month_limit':
      return 'В проекте можно выбрать не более 24 уникальных месяцев.'
    case 'unknown_category':
      return 'Выберите категорию из свежих подсказок.'
    default:
      return 'Операция не завершена. Проверьте соединение и повторите попытку.'
  }
}

export function errorText(error: unknown): string {
  if (error instanceof ApiError && error.status === 403)
    return 'Нет доступа к компании. Выберите доступную компанию.'
  if (error instanceof ApiError && error.status === 401)
    return 'Сессия завершена. Войдите в приложение снова.'
  return researchFailure(error instanceof ApiError ? error.code : null)
}

export function uncertainWrite(error: unknown): boolean {
  return (
    !(error instanceof ApiError) || error.status >= 500 || error.status === 408
  )
}

export function dateTime(value: string | null): string {
  return value === null
    ? '—'
    : new Intl.DateTimeFormat('ru-RU', {
        timeZone: 'Europe/Moscow',
        dateStyle: 'short',
        timeStyle: 'short',
      }).format(new Date(value))
}

export function calendarDate(): string {
  return new Intl.DateTimeFormat('sv-SE', {
    timeZone: 'Europe/Moscow',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date())
}

export function startInput(
  previous: StartInput | null,
  version: number,
  calendar: string,
): StartInput {
  return (
    previous ?? {
      requestId: requestId(),
      expectedVersion: version,
      previewCalendarDate: calendar,
    }
  )
}
