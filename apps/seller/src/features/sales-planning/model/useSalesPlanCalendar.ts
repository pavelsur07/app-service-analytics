import { queryOptions } from '@tanstack/react-query'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'

type CalendarPage = components['schemas']['SalesPlanCalendarResponse']
/** Internal aggregate of all API pages; a single API page remains bounded to 200. */
type CalendarSnapshot = Omit<CalendarPage, 'nextCursor'>

export function calendarQueryOptions(
  companyId: string,
  planId: string,
  headerVersion: number,
) {
  return queryOptions({
    queryKey: companyQueryKey(companyId, 'sales-planning', 'calendar', {
      planId,
      headerVersion,
    }),
    enabled: companyId !== '' && planId !== '',
    retry: false,
    retryOnMount: false,
    queryFn: async (): Promise<CalendarSnapshot> => {
      if (companyId === '' || planId === '') {
        throw new Error('Нужны компания и план.')
      }
      const api = createCompanyApiClient(companyId)
      const entries: CalendarPage['entries'] = []
      const ids = new Set<string>()
      const cursors = new Set<string>()
      let first: CalendarPage | null = null
      let cursor: string | null = null
      for (let index = 0; index < 3; index += 1) {
        const search = new URLSearchParams({ limit: '200' })
        if (cursor !== null) search.set('cursor', cursor)
        const page: CalendarPage = await api.get(
          `/sales-plans/${encodeURIComponent(planId)}/calendar?${search.toString()}`,
        )
        if (
          page.planId !== planId ||
          page.entries.length > 200 ||
          (first !== null &&
            (page.version !== first.version ||
              page.status !== first.status ||
              page.seasonStart.date !== first.seasonStart.date ||
              page.seasonEnd.date !== first.seasonEnd.date))
        ) {
          throw new Error(
            'Календарь изменился во время загрузки. Перечитайте данные.',
          )
        }
        first ??= page
        for (const entry of page.entries) {
          if (ids.has(entry.id)) {
            throw new Error(
              'Календарь загружен с повторами. Перечитайте данные.',
            )
          }
          ids.add(entry.id)
          entries.push(entry)
        }
        if (entries.length > 500) {
          throw new Error('В календаре больше 500 событий.')
        }
        const next = page.nextCursor ?? null
        if (next === null) {
          return {
            planId: first.planId,
            version: first.version,
            status: first.status,
            seasonStart: first.seasonStart,
            seasonEnd: first.seasonEnd,
            entries,
          }
        }
        if (page.entries.length === 0 || cursors.has(next)) {
          throw new Error('Не удалось дочитать календарь. Перечитайте данные.')
        }
        cursors.add(next)
        cursor = next
      }
      throw new Error('Не удалось дочитать календарь. Сохранение недоступно.')
    },
  })
}
