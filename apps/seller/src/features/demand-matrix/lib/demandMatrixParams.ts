// Тот же предел, что проверяет сервер (ShowDemandMatrixReportController):
// длиннее — 422, поэтому из ссылки он обрезается, а не уходит в запрос.
export const MAX_SEARCH_LENGTH = 100

export function parseDemandMatrixSearch(search: URLSearchParams): string {
  return (search.get('search') ?? '').trim().slice(0, MAX_SEARCH_LENGTH)
}

// Новый поиск сбрасывает курсор: сервер привязывает его к поиску
// и чужой отвергает 422.
export function demandMatrixSearchWithText(
  current: URLSearchParams,
  text: string,
): URLSearchParams {
  const next = new URLSearchParams(current)
  const value = text.trim().slice(0, MAX_SEARCH_LENGTH)
  if (value === '') {
    next.delete('search')
  } else {
    next.set('search', value)
  }
  next.delete('cursor')

  return next
}

export function demandMatrixSearchWithCursor(
  current: URLSearchParams,
  cursor: string | null,
): URLSearchParams {
  const next = new URLSearchParams(current)
  if (cursor === null) {
    next.delete('cursor')
  } else {
    next.set('cursor', cursor)
  }

  return next
}
