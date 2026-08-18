import { CircleAlert, CircleX, Puzzle, TrendingDown } from 'lucide-react'
import { useParams } from 'react-router'

import { Badge, Card, StatusPanel } from '../../../../../../packages/ui/src'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import { coInvestmentView, observedAgo } from '../lib/coInvestment'
import {
  usePriceOverview,
  type PriceOverviewItem,
} from '../model/usePriceOverview'

/**
 * Экран отслеживания цен и соинвеста (ADR-014).
 *
 * Пустых состояний три, и они означают разное: артикулы не включены,
 * включены но ещё не обойдены, обойдены давно. Свести их к одному
 * «данных нет» значит сделать экран, который врёт: в первом случае
 * продавцу надо нажать кнопку в расширении, во втором — подождать,
 * в третьем — проверить, запущен ли браузер.
 *
 * Арифметики над деньгами здесь нет (CLAUDE.md §10): разницу считает
 * сервер, проценты и возраст — чистые функции в lib/.
 */
export function PriceOverviewPage() {
  const { companyId = '' } = useParams()
  const { data, isPending, isError, error } = usePriceOverview(companyId)

  if (isPending) {
    return (
      <StatusPanel
        icon={<TrendingDown aria-hidden="true" size={20} />}
        title="Загружаем цены…"
      />
    )
  }

  if (isError) {
    return (
      <StatusPanel
        description={
          error instanceof Error
            ? error.message
            : 'Попробуйте обновить страницу.'
        }
        icon={<CircleX aria-hidden="true" size={20} />}
        role="alert"
        title="Не удалось загрузить цены"
        tone="negative"
      />
    )
  }

  const items = data.items

  if (0 === items.length) {
    return (
      <StatusPanel
        description="Отслеживание включается в расширении Conwix, на карточке товара Ozon: кнопка «Отслеживать цену» под цифрами продаж."
        icon={<Puzzle aria-hidden="true" size={20} />}
        title="Ни один артикул не отслеживается"
      />
    )
  }

  return (
    <section className="flex flex-col gap-4">
      <header className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold">Цены и соинвест</h1>
        <p className="text-sm text-text-muted">
          Соинвест — сколько Ozon доплачивает поверх вашей скидки: разница между
          ценой кабинета и той, что видит покупатель. Снимки делает расширение,
          пока запущен браузер.
        </p>
      </header>

      <Card>
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="text-left text-text-muted">
              <th className="py-2 pr-3 font-medium">Товар</th>
              <th className="py-2 pr-3 text-right font-medium">В кабинете</th>
              <th className="py-2 pr-3 text-right font-medium">На витрине</th>
              <th className="py-2 pr-3 text-right font-medium">Соинвест</th>
              <th className="py-2 text-right font-medium">Снято</th>
            </tr>
          </thead>
          <tbody>
            {items.map((item) => (
              <PriceRow key={item.marketplaceSku} item={item} />
            ))}
          </tbody>
        </table>
      </Card>
    </section>
  )
}

function PriceRow({ item }: { item: PriceOverviewItem }) {
  const currency = item.currency ?? 'RUB'
  const view = coInvestmentView(item.coInvestmentMinor, item.sellerPriceMinor)
  const waiting = null === item.observedAt || undefined === item.observedAt

  return (
    <tr className="border-t border-border-subtle">
      <td className="py-2 pr-3">
        <div>{item.name ?? item.marketplaceSku}</div>
        {null !== item.name && undefined !== item.name && (
          <div className="text-xs text-text-muted">{item.marketplaceSku}</div>
        )}
      </td>
      <td className="py-2 pr-3 text-right tabular-nums">
        {money(item.sellerPriceMinor, currency)}
      </td>
      <td className="py-2 pr-3 text-right tabular-nums">
        {money(item.displayedPriceMinor, currency)}
      </td>
      <td className="py-2 pr-3 text-right tabular-nums">
        {null === item.coInvestmentMinor ||
        undefined === item.coInvestmentMinor ? (
          <span className="text-text-muted">—</span>
        ) : (
          <span className="inline-flex items-center gap-2">
            {view.suspicious && (
              <CircleAlert
                aria-label="Витрина дороже кабинета — похоже на ошибку чтения"
                className="size-4 text-text-muted"
              />
            )}
            {formatMinorAmount(item.coInvestmentMinor, currency)}
            {null !== view.percent && (
              <Badge tone={view.suspicious ? 'warning' : 'positive'}>
                {view.percent}%
              </Badge>
            )}
          </span>
        )}
      </td>
      <td className="py-2 text-right text-text-muted">
        {waiting ? (
          <span className="inline-flex items-center gap-1">
            <TrendingDown className="size-4" />
            ещё не снимали
          </span>
        ) : (
          observedAgo(item.observedAt, new Date())
        )}
      </td>
    </tr>
  )
}

function money(minor: number | null | undefined, currency: string) {
  return null === minor || undefined === minor ? (
    <span className="text-text-muted">—</span>
  ) : (
    formatMinorAmount(minor, currency)
  )
}
