import { useSearchParams } from 'react-router'
import { Button, Card } from '../../../../../../packages/ui/src'
import {
  campaignSelectionIssue,
  catalogState,
  catalogPayment,
  catalogTime,
} from '../lib/catalog'
import { adsError } from '../lib/presentation'
import { useCampaignCatalog } from '../model/queries'

export function CampaignCatalog({
  companyId,
  accountId,
  enabled,
}: {
  companyId: string
  accountId: string
  enabled: boolean
}) {
  const [search, setSearch] = useSearchParams()
  const cursor = search.get('catalogCursor')
  const includeArchived = search.get('catalogArchived') === 'true'
  const setCursor = (value: string | null) => {
    const next = new URLSearchParams(search)
    if (value === null) next.delete('catalogCursor')
    else next.set('catalogCursor', value)
    setSearch(next)
  }
  const setIncludeArchived = (value: boolean) => {
    const next = new URLSearchParams(search)
    next.delete('catalogCursor')
    if (value) next.set('catalogArchived', 'true')
    else next.delete('catalogArchived')
    setSearch(next)
  }
  const catalog = useCampaignCatalog(
    companyId,
    accountId,
    cursor,
    includeArchived,
  )
  if (accountId === '')
    return (
      <Card>
        <p>Выберите кабинет, чтобы увидеть его рекламные кампании.</p>
      </Card>
    )
  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold">Рекламные кампании кабинета</h2>
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={includeArchived}
            onChange={(event) => {
              setIncludeArchived(event.target.checked)
            }}
          />
          Показать архивные
        </label>
      </div>
      {!enabled && (
        <p className="text-sm text-text-secondary">
          Каталог доступен для просмотра. Настройка наблюдения пока недоступна.
        </p>
      )}
      {catalog.isPending && (
        <p role="status">Загружаем сохранённый список кампаний…</p>
      )}
      {catalog.isError && (
        <Card tone="negative">
          <p role="alert">{adsError(catalog.error)}</p>
          <Button
            variant="secondary"
            onClick={() => {
              setCursor(null)
              void catalog.refetch()
            }}
          >
            Повторить загрузку кампаний
          </Button>
        </Card>
      )}
      {catalog.isSuccess && (
        <>
          {catalog.data.status === 'not_loaded' ? (
            <Card>
              <p>Список кампаний этого кабинета ещё не загружен.</p>
              <p className="mt-1 text-sm text-text-secondary">
                Проверьте рекламное подключение и дождитесь успешной
                синхронизации. Это не означает, что кампаний в Ozon нет.
              </p>
            </Card>
          ) : (
            <>
              <p className="text-sm text-text-muted">
                {catalog.data.restored
                  ? 'Ответ впервые сохранён:'
                  : 'Список получен:'}{' '}
                {catalog.data.receivedAt == null
                  ? 'время неизвестно'
                  : catalogTime(catalog.data.receivedAt)}{' '}
                · Europe/Moscow. Текущий статус в Ozon мог измениться.
              </p>
              {catalog.data.restored && (
                <Card tone="warning">
                  <p role="status">
                    Каталог восстановлен из сохранённого ответа. Его
                    актуальность не подтверждена; дождитесь следующей
                    синхронизации.
                  </p>
                </Card>
              )}
              {catalog.data.status === 'partial' && (
                <Card tone="warning">
                  <p role="status">
                    Полнота списка не подтверждена. Показаны только полученные
                    кампании; отсутствие кампании не означает её удаление.
                  </p>
                </Card>
              )}
              {catalog.data.items.length === 0 ? (
                <Card>
                  <p>
                    {catalog.data.status === 'ready' &&
                    catalog.data.savedCount === 0
                      ? 'Ozon вернул пустой список кампаний.'
                      : includeArchived
                        ? 'На этой странице кампаний нет.'
                        : 'Неархивных кампаний на этой странице нет. Включите показ архивных или вернитесь в начало.'}
                  </p>
                </Card>
              ) : (
                <div className="overflow-x-auto rounded-md border border-border-default">
                  <table className="w-full text-left text-sm">
                    <thead className="bg-surface-raised text-text-secondary">
                      <tr>
                        <th className="px-4 py-3">Кампания</th>
                        <th className="px-4 py-3">Статус Ozon</th>
                        <th className="px-4 py-3">Тип / оплата</th>
                        <th className="px-4 py-3">Наблюдение</th>
                      </tr>
                    </thead>
                    <tbody>
                      {catalog.data.items.map((campaign) => (
                        <tr
                          key={campaign.id}
                          className="border-t border-border-default"
                        >
                          <td className="px-4 py-3">
                            <p className="font-medium">
                              {campaign.title || 'Название не передано'}
                            </p>
                            <p className="text-text-muted">ID {campaign.id}</p>
                          </td>
                          <td className="px-4 py-3">
                            {catalogState(campaign.state)}
                          </td>
                          <td className="px-4 py-3">
                            {campaign.advObjectType === 'SKU'
                              ? 'Товарная'
                              : 'Другой тип'}{' '}
                            · {catalogPayment(campaign.paymentType)}
                          </td>
                          <td className="px-4 py-3">
                            {campaignSelectionIssue(campaign) ??
                              (enabled
                                ? 'Можно выбрать во вкладке «Стратегии»'
                                : 'Подходит для наблюдения CPC')}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </>
          )}
        </>
      )}
      <div className="flex flex-wrap gap-2">
        <Button
          variant="secondary"
          disabled={cursor === null}
          onClick={() => setCursor(null)}
        >
          В начало
        </Button>
        <Button
          variant="secondary"
          disabled={
            !catalog.isSuccess ||
            catalog.data.nextCursor === null ||
            catalog.isFetching
          }
          onClick={() => {
            if (catalog.data?.nextCursor != null)
              setCursor(catalog.data.nextCursor)
          }}
        >
          Дальше
        </Button>
        <Button
          variant="ghost"
          disabled={catalog.isFetching}
          onClick={() => void catalog.refetch()}
        >
          Обновить
        </Button>
      </div>
    </>
  )
}
