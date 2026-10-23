import { Navigate, useSearchParams } from 'react-router'

import { Button } from '../../../../packages/ui/src'
import { DemandMatrixReport } from '../features/demand-matrix/ui/DemandMatrixReport'
import {
  localizationSearchFromDemandMatrix,
  localizationSearchWithView,
  type LocalizationView,
  parseLocalizationView,
} from '../features/localization/lib/localizationParams'
import { LocalizationReport } from '../features/localization/ui/LocalizationReport'

const TABS: { view: LocalizationView; label: string }[] = [
  { view: 'demand', label: 'Спрос' },
  { view: 'clusters', label: 'Кластеры доставки' },
  { view: 'items', label: 'Что куда довезти' },
]

/**
 * Раздел «Локализация»: спрос по кластерам покупателя и локальность
 * доставки — один вопрос «где продаётся и откуда везём», поэтому один
 * пункт меню. Вкладки собираются здесь, а не в фиче: features/A
 * не импортирует из features/B.
 *
 * Вкладки видны всегда, над периодом и сводкой: у спроса своё окно
 * и свой поиск, а локализация при пустом периоде не рисует ничего,
 * кроме «пока считать нечего», — спрос из-под неё был бы недоступен.
 */
export function LocalizationSection() {
  const [search, setSearch] = useSearchParams()
  const view = parseLocalizationView(search.get('view'))

  return (
    <section className="flex flex-col gap-4">
      <header className="flex flex-col gap-3">
        <h1 className="text-xl font-semibold">Локализация</h1>
        <div
          aria-label="Отчёт"
          className="flex flex-wrap items-center gap-1"
          role="tablist"
        >
          {TABS.map((tab) => (
            <Button
              aria-controls={`localization-panel-${tab.view}`}
              aria-selected={tab.view === view}
              id={`localization-tab-${tab.view}`}
              key={tab.view}
              onClick={() => {
                setSearch(localizationSearchWithView(search, tab.view), {
                  replace: true,
                })
              }}
              role="tab"
              size="compact"
              type="button"
              variant={tab.view === view ? 'primary' : 'ghost'}
            >
              {tab.label}
            </Button>
          ))}
        </div>
      </header>

      <div
        aria-labelledby={`localization-tab-${view}`}
        id={`localization-panel-${view}`}
        role="tabpanel"
      >
        {view === 'demand' ? (
          <DemandMatrixReport />
        ) : (
          <LocalizationReport view={view} />
        )}
      </div>
    </section>
  )
}

/** Старый адрес матрицы спроса — закладки и ссылки ведут во вкладку. */
export function DemandMatrixRedirect() {
  const [search] = useSearchParams()
  const next = localizationSearchFromDemandMatrix(search)

  return <Navigate relative="path" replace to={`../localization?${next}`} />
}
