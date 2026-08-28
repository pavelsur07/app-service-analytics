import type { paths } from '../../../api/schema'

// Типы порядка берутся из сгенерированной схемы, а не описываются руками:
// добавится показатель на бэкенде — здесь перестанет сходиться типизация,
// а не разойдётся молча.
type Query = NonNullable<
  paths['/api/companies/{companyId}/unit-economics']['get']['parameters']['query']
>

export type SortKey = NonNullable<Query['sort']>
export type SortDirection = NonNullable<Query['direction']>

// satisfies, а не аннотация: список остаётся кортежем литералов,
// но лишнее значение в нём не пройдёт типизацию.
const SORT_KEYS = [
  'delivered',
  'revenue',
  'commission',
  'expenses',
  'cost',
  'margin',
] as const satisfies readonly SortKey[]

export const PAGE_SIZES = [25, 30, 40] as const

export type PageSize = (typeof PAGE_SIZES)[number]

const DEFAULT_PAGE_SIZE: PageSize = 25
const DEFAULT_SORT: SortKey = 'revenue'
const DEFAULT_DIRECTION: SortDirection = 'desc'
const DEFAULT_DAYS = 30

export const WINDOWS = [7, 30, 90] as const

/**
 * Разбор адресной строки. Мусор молча заменяется умолчанием, и это
 * осознанно расходится с бэкендом, где то же значение даёт 422.
 *
 * Разница в том, кто автор строки. В запросе к API её собирает наш же
 * код, и расхождение там — дефект, о котором надо узнать. Адрес правит
 * человек: устаревшая закладка с `?limit=999` должна показать экран,
 * а не сломать его. Наружу при этом уходят только значения из списков
 * ниже, так что строгость бэкенда ничего не теряет.
 */
export function parsePageSize(raw: string | null): PageSize {
  const value = Number(raw)

  return PAGE_SIZES.find((size) => size === value) ?? DEFAULT_PAGE_SIZE
}

export function parseSort(raw: string | null): SortKey {
  return SORT_KEYS.find((key) => key === raw) ?? DEFAULT_SORT
}

export function parseDirection(raw: string | null): SortDirection {
  return raw === 'asc' || raw === 'desc' ? raw : DEFAULT_DIRECTION
}

export function parseDays(raw: string | null): number {
  const value = Number(raw)

  return WINDOWS.find((window) => window === value) ?? DEFAULT_DAYS
}

/**
 * С какой стороны показатель интересен при первом клике по колонке.
 *
 * Комиссия, расходы площадки и себестоимость хранятся отрицательными —
 * знак часть данных, а не оформление. По убыванию наверх всплыл бы
 * самый дешёвый товар, то есть ровно обратное тому, зачем на эту
 * колонку нажимают. Стрелка при этом не врёт: она описывает порядок
 * чисел, которые видно на экране, а видно их со знаком.
 */
export function initialDirection(sort: SortKey): SortDirection {
  return sort === 'commission' || sort === 'expenses' || sort === 'cost'
    ? 'asc'
    : 'desc'
}

/**
 * Следующее состояние сортировки по клику: активная колонка
 * переворачивается, неактивная берётся со своей стороны.
 */
export function nextSort(
  clicked: SortKey,
  current: SortKey,
  direction: SortDirection,
): { sort: SortKey; direction: SortDirection } {
  if (clicked !== current) {
    return { sort: clicked, direction: initialDirection(clicked) }
  }

  return { sort: clicked, direction: direction === 'asc' ? 'desc' : 'asc' }
}
