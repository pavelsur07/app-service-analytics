import { useEffect, useState } from 'react'
import { Button } from '../../../../../../packages/ui/src'
import { campaignSelectionIssue } from '../lib/catalog'
import { adsError } from '../lib/presentation'
import { useCampaignCatalog } from '../model/queries'

export function CampaignSelector({
  companyId,
  accountId,
  value,
  onChange,
  onAvailable,
}: {
  companyId: string
  accountId: string
  value: string
  onChange: (id: string) => void
  onAvailable: (available: boolean) => void
}) {
  const [cursor, setCursor] = useState<string | null>(null)
  const catalog = useCampaignCatalog(companyId, accountId, cursor)
  const selected = catalog.data?.items.find((item) => item.id === value)
  const valid =
    catalog.isSuccess &&
    selected !== undefined &&
    campaignSelectionIssue(selected) === null
  useEffect(() => {
    onAvailable(valid)
  }, [valid, onAvailable])
  return (
    <div className="flex flex-col gap-2">
      <label className="flex flex-col gap-1 text-sm">
        Кампания Ozon
        <select
          className="rounded-md border border-border-default bg-surface-raised px-3 py-2"
          value={value}
          disabled={accountId === '' || !catalog.isSuccess}
          onChange={(event) => onChange(event.target.value)}
        >
          <option value="">Выберите кампанию из сохранённого списка</option>
          {value !== '' && selected === undefined && (
            <option value={value} disabled>
              ID {value} — на другой странице
            </option>
          )}
          {catalog.data?.items.map((item) => (
            <option
              key={item.id}
              value={item.id}
              disabled={campaignSelectionIssue(item) !== null}
            >
              {item.title || 'Название не передано'} · {item.id}
              {campaignSelectionIssue(item) === null ? '' : ' — недоступна'}
            </option>
          ))}
        </select>
      </label>
      {catalog.isFetching && (
        <p role="status" className="text-sm text-text-secondary">
          Загружаем кампании…
        </p>
      )}
      {catalog.isError && (
        <p role="alert" className="text-sm text-negative-text">
          {adsError(catalog.error)}
        </p>
      )}
      {catalog.data?.status === 'not_loaded' && (
        <p role="status" className="text-sm text-text-secondary">
          Список кампаний ещё не загружен.
        </p>
      )}
      {catalog.data?.restored && (
        <p role="status" className="text-sm text-warning-text">
          Каталог восстановлен из сохранённого ответа; актуальность требует
          синхронизации.
        </p>
      )}
      {catalog.data?.status === 'partial' && (
        <p role="status" className="text-sm text-warning-text">
          Получена только часть списка кампаний.
        </p>
      )}
      <p className="text-sm text-text-muted">
        Для нового наблюдения выберите неархивную товарную кампанию CPC.
        Сохранение не запускает рекламу.
      </p>
      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          variant="ghost"
          disabled={cursor === null}
          onClick={() => setCursor(null)}
        >
          В начало списка кампаний
        </Button>
        <Button
          type="button"
          variant="ghost"
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
          Далее кампании
        </Button>
        {catalog.isError && (
          <Button
            type="button"
            variant="secondary"
            onClick={() => {
              setCursor(null)
              void catalog.refetch()
            }}
          >
            Повторить список кампаний
          </Button>
        )}
      </div>
    </div>
  )
}
