import { useState } from 'react'
import { useSearchParams } from 'react-router'
import {
  nextSort,
  parseDays,
  parseDirection,
  parsePageSize,
  parseSort,
} from '../lib/tableParams'
import type { PageSize, SortKey } from '../lib/tableParams'
import type { UnitEconomicsParams } from './useUnitEconomics'

interface Stack {
  key: string
  cursors: (string | null)[]
}

/**
 * Состояние экрана: окно, размер страницы и порядок живут в адресе,
 * курсор — нет.
 *
 * Адрес — источник правды для фильтров, периода и страницы
 * (docs/patterns.md, «Состояние»): ссылку можно переслать, экран
 * переживает перезагрузку. Курсор туда не идёт: «Назад» при keyset
 * требует стопку предыдущих курсоров, а стопка в query-параметре
 * хуже задачи, которую решает.
 */
export function useUnitEconomicsView(): {
  params: UnitEconomicsParams
  canGoBack: boolean
  setDays: (days: number) => void
  setLimit: (limit: PageSize) => void
  toggleSort: (clicked: SortKey) => void
  goNext: (cursor: string) => void
  goBack: () => void
} {
  const [search, setSearch] = useSearchParams()

  const days = parseDays(search.get('days'))
  const limit = parsePageSize(search.get('limit'))
  const sort = parseSort(search.get('sort'))
  const direction = parseDirection(search.get('direction'))

  const viewKey = `${days}:${limit}:${sort}:${direction}`
  const [stack, setStack] = useState<Stack>({ key: viewKey, cursors: [null] })

  // Курсор принадлежит представлению: при другом окне, размере страницы
  // или порядке он указывает в другой отчёт. Сброс выведен из ключа,
  // а не сделан эффектом — эффект писал бы состояние уже после рендера,
  // и один запрос успел бы уйти со старым курсором. Заодно это
  // покрывает кнопку «Назад» браузера и правку адреса руками.
  const cursors = stack.key === viewKey ? stack.cursors : [null]
  const cursor = cursors[cursors.length - 1] ?? null

  // replace, а не push: пагинация живёт в состоянии компонента и кнопке
  // «Назад» браузера не видна. Класть в историю смену сортировки значило
  // бы, что одна кнопка на одном экране означает то одно, то другое.
  const write = (patch: Record<string, string>): void => {
    const next = new URLSearchParams(search)
    for (const [key, value] of Object.entries(patch)) {
      next.set(key, value)
    }

    setSearch(next, { replace: true })
  }

  return {
    params: { days, limit, sort, direction, cursor },
    canGoBack: cursors.length > 1,
    setDays: (value) => {
      write({ days: String(value) })
    },
    setLimit: (value) => {
      write({ limit: String(value) })
    },
    toggleSort: (clicked) => {
      const next = nextSort(clicked, sort, direction)
      write({ sort: next.sort, direction: next.direction })
    },
    goNext: (value) => {
      setStack({ key: viewKey, cursors: [...cursors, value] })
    },
    goBack: () => {
      setStack({ key: viewKey, cursors: cursors.slice(0, -1) })
    },
  }
}
