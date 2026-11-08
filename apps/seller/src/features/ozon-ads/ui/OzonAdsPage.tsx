import { useEffect, useState } from 'react'
import {
  Link,
  useBeforeUnload,
  useBlocker,
  useParams,
  useSearchParams,
} from 'react-router'

import { Button, Card } from '../../../../../../packages/ui/src'
import { useConnections } from '../../../shared/model/useConnections'
import { useOzonAdsAvailability } from '../../../shared/model/useOzonAdsAvailability'
import { adsError, parseTab, TABS } from '../lib/presentation'
import { useEvents, usePolicies } from '../model/queries'
import type { Policy } from '../model/types'
import { EventTable } from './EventTable'
import { PolicyForm } from './PolicyForm'
import { PolicyTable } from './PolicyTable'

export function OzonAdsPage() {
  const { companyId } = useParams<{ companyId: string }>()
  return companyId === undefined ? null : (
    <AdsWorkspace key={companyId} companyId={companyId} />
  )
}

function AdsWorkspace({ companyId }: { companyId: string }) {
  const availability = useOzonAdsAvailability(companyId)
  const connections = useConnections(companyId)
  const [search, setSearch] = useSearchParams()
  const tab = parseTab(search.get('view'))
  const accounts = (connections.data?.connections ?? [])
    .filter((a) => a.marketplace === 'ozon')
    .map((a) => ({
      id: a.id,
      label: `Ozon · ${a.externalShopId}`,
      available: a.state === 'active' && a.advertisingState === 'active',
    }))
  const requestedAccount = search.get('account') ?? ''
  const account = accounts.some((a) => a.id === requestedAccount)
    ? requestedAccount
    : ''
  const status = ['true', 'false'].includes(search.get('status') ?? '')
    ? (search.get('status') ?? 'all')
    : 'all'
  const cursor = search.get(tab === 'history' ? 'eventCursor' : 'cursor')
  const [editor, setEditor] = useState<{ policy: Policy | null } | null>(null)
  const [dirty, setDirty] = useState(false)
  const [pending, setPending] = useState(false)
  const [confirmClose, setConfirmClose] = useState(false)
  const [now, setNow] = useState(Date.now)
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 30_000)
    return () => clearInterval(timer)
  }, [])
  const blocker = useBlocker(
    ({ currentLocation, nextLocation }) =>
      (dirty || pending) &&
      (currentLocation.pathname !== nextLocation.pathname ||
        currentLocation.search !== nextLocation.search),
  )
  useBeforeUnload((event) => {
    if (dirty || pending) {
      event.preventDefault()
      event.returnValue = ''
    }
  })
  const enabled =
    availability.data?.enabled === true &&
    !availability.isError &&
    connections.isSuccess &&
    !connections.isError
  const policies = usePolicies(
    companyId,
    account,
    cursor,
    status,
    enabled && (tab === 'campaigns' || tab === 'strategies'),
  )
  const events = useEvents(
    companyId,
    account,
    cursor,
    enabled && tab === 'history',
  )
  const close = () => {
    setEditor(null)
    setDirty(false)
    setPending(false)
    setConfirmClose(false)
  }
  const changeSearch = (key: string, value: string) => {
    const next = new URLSearchParams(search)
    if (value === '') next.delete(key)
    else next.set(key, value)
    if (key === 'account' || key === 'status') {
      next.delete('cursor')
      next.delete('eventCursor')
    }
    setSearch(next)
  }
  const policyVisible = tab === 'campaigns' || tab === 'strategies'
  const current = tab === 'history' ? events : policies
  const root = `/companies/${encodeURIComponent(companyId)}`
  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-xl font-semibold">Озон Ads</h1>
      <Card tone="warning">
        <p className="font-medium">
          Режим наблюдения: реклама не изменяется автоматически
        </p>
        <p className="mt-1 text-sm">
          Настройки используются для расчёта предполагаемых решений. Защита
          бюджета не активна; свежесть источника и защитный запас не
          подтверждены.
        </p>
      </Card>
      <div className="flex flex-wrap items-center gap-4">
        <label className="flex items-center gap-2 text-sm">
          Кабинет
          <select
            aria-label="Кабинет"
            className="rounded-md border border-border-default bg-surface-raised px-3 py-2"
            value={account}
            onChange={(event) => changeSearch('account', event.target.value)}
          >
            <option value="">Все подключения Ozon</option>
            {accounts.map((a) => (
              <option key={a.id} value={a.id}>
                {a.label}
                {a.available ? '' : ' — проверьте подключение'}
              </option>
            ))}
          </select>
        </label>
        <span className="text-sm text-text-muted">
          Europe/Moscow · UTC+3 · RUB
        </span>
      </div>
      <div
        aria-label="Озон Ads"
        role="tablist"
        className="flex flex-wrap gap-1"
      >
        {TABS.map((item, index) => (
          <Button
            key={item.id}
            type="button"
            role="tab"
            id={`ads-tab-${item.id}`}
            aria-controls={`ads-panel-${item.id}`}
            aria-selected={tab === item.id}
            tabIndex={tab === item.id ? 0 : -1}
            variant={tab === item.id ? 'primary' : 'ghost'}
            size="compact"
            onClick={() => changeSearch('view', item.id)}
            onKeyDown={(event) => {
              let next: number | null = null
              if (event.key === 'ArrowRight') next = (index + 1) % TABS.length
              if (event.key === 'ArrowLeft')
                next = (index + TABS.length - 1) % TABS.length
              if (event.key === 'Home') next = 0
              if (event.key === 'End') next = TABS.length - 1
              if (next !== null) {
                event.preventDefault()
                const target = TABS[next]
                if (target !== undefined) {
                  changeSearch('view', target.id)
                  document.getElementById(`ads-tab-${target.id}`)?.focus()
                }
              }
            }}
          >
            {item.label}
          </Button>
        ))}
      </div>
      {blocker.state === 'blocked' && (
        <Card tone="warning">
          <p role="alert">
            {pending
              ? 'Дождитесь результата сохранения.'
              : 'Есть несохранённые изменения. Покинуть форму?'}
          </p>
          <div className="mt-2 flex flex-wrap gap-2">
            <Button
              type="button"
              variant="secondary"
              onClick={() => blocker.reset()}
            >
              Остаться
            </Button>
            {!pending && (
              <Button
                type="button"
                onClick={() => {
                  close()
                  blocker.proceed()
                }}
              >
                Отбросить и перейти
              </Button>
            )}
          </div>
        </Card>
      )}
      {confirmClose && (
        <Card tone="warning">
          <p role="alert">Отбросить несохранённые изменения?</p>
          <div className="mt-2 flex gap-2">
            <Button
              type="button"
              variant="secondary"
              onClick={() => setConfirmClose(false)}
            >
              Продолжить редактирование
            </Button>
            <Button
              type="button"
              disabled={pending}
              onClick={() => {
                if (!pending) close()
              }}
            >
              Отбросить изменения
            </Button>
          </div>
        </Card>
      )}
      <div
        role="tabpanel"
        id={`ads-panel-${tab}`}
        aria-labelledby={`ads-tab-${tab}`}
        tabIndex={0}
        className="flex flex-col gap-4"
      >
        {(availability.isPending || connections.isPending) && (
          <p role="status">Проверяем доступность раздела…</p>
        )}
        {(availability.isError || connections.isError) && (
          <Card tone="negative">
            <p role="alert">
              {adsError(availability.error ?? connections.error)}
            </p>
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                void availability.refetch()
                void connections.refetch()
              }}
            >
              Повторить проверку
            </Button>
          </Card>
        )}
        {availability.isSuccess && !availability.data.enabled && (
          <Card>
            <p>Раздел наблюдения пока выключен.</p>
          </Card>
        )}
        {tab === 'strategies' && editor !== null && (
          <PolicyForm
            key={editor.policy?.id ?? 'new'}
            companyId={companyId}
            blocked={!enabled}
            policy={editor.policy}
            accounts={accounts}
            defaultAccount={account}
            onDirty={setDirty}
            onPending={setPending}
            onSaved={close}
            onClose={() => {
              if (pending) return
              if (dirty) setConfirmClose(true)
              else close()
            }}
          />
        )}

        {enabled && (
          <>
            {account !== '' &&
              !accounts.some((a) => a.id === account && a.available) && (
                <Card tone="warning">
                  <p>
                    Рекламное подключение недоступно. Проверьте ключ и права в
                    разделе подключений.
                  </p>
                  <Link to={`${root}/connections`}>Открыть подключения</Link>
                </Card>
              )}
            {policyVisible && (
              <>
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <label className="flex items-center gap-2 text-sm">
                    Наблюдение
                    <select
                      aria-label="Фильтр наблюдения"
                      className="rounded-md border border-border-default bg-surface-raised px-3 py-2"
                      value={status}
                      onChange={(event) =>
                        changeSearch('status', event.target.value)
                      }
                    >
                      <option value="all">Все</option>
                      <option value="true">Включено</option>
                      <option value="false">Выключено</option>
                    </select>
                  </label>
                  {tab === 'strategies' && editor === null && (
                    <Button
                      type="button"
                      disabled={!accounts.some((a) => a.available)}
                      onClick={() => setEditor({ policy: null })}
                    >
                      Добавить наблюдение
                    </Button>
                  )}
                </div>
                {policies.isSuccess && (
                  <>
                    {policies.data.items.length === 0 ? (
                      <Card>
                        <p>Для выбранного фильтра наблюдений нет.</p>
                        {!accounts.some((a) => a.available) ? (
                          <Link to={`${root}/connections`}>
                            Подключить рекламный кабинет
                          </Link>
                        ) : (
                          <p className="mt-1 text-sm text-text-muted">
                            Добавьте кампанию во вкладке «Стратегии».
                          </p>
                        )}
                      </Card>
                    ) : (
                      <PolicyTable
                        items={policies.data.items}
                        accounts={accounts}
                        blocked={pending}
                        now={now}
                        onEdit={(policy) => {
                          if (pending) return
                          if (dirty) {
                            setConfirmClose(true)
                            return
                          }
                          setEditor({ policy })
                          changeSearch('view', 'strategies')
                        }}
                      />
                    )}
                  </>
                )}
              </>
            )}
            {tab === 'history' &&
              events.isSuccess &&
              (events.data.items.length === 0 ? (
                <Card>
                  <p>Событий для выбранного кабинета пока нет.</p>
                </Card>
              ) : (
                <EventTable items={events.data.items} />
              ))}
            {(policyVisible || tab === 'history') && (
              <>
                {current.isPending && (
                  <p role="status">Загружаем данные наблюдения…</p>
                )}
                {current.isError && (
                  <Card tone="negative">
                    <p role="alert">{adsError(current.error)}</p>
                    <Button
                      type="button"
                      variant="secondary"
                      onClick={() => {
                        void current.refetch()
                      }}
                    >
                      Повторить загрузку
                    </Button>
                  </Card>
                )}
                <div className="flex flex-wrap gap-2">
                  <Button
                    type="button"
                    variant="secondary"
                    disabled={cursor === null}
                    onClick={() =>
                      changeSearch(
                        tab === 'history' ? 'eventCursor' : 'cursor',
                        '',
                      )
                    }
                  >
                    В начало
                  </Button>
                  <Button
                    type="button"
                    variant="secondary"
                    disabled={
                      !current.isSuccess ||
                      current.data.nextCursor === null ||
                      current.isFetching
                    }
                    onClick={() => {
                      if (current.data?.nextCursor != null)
                        changeSearch(
                          tab === 'history' ? 'eventCursor' : 'cursor',
                          current.data.nextCursor,
                        )
                    }}
                  >
                    Дальше
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    disabled={current.isFetching}
                    onClick={() => {
                      void current.refetch()
                    }}
                  >
                    Обновить
                  </Button>
                </div>
              </>
            )}
            {tab === 'settings' && (
              <Card>
                <h2 className="text-lg font-semibold">Условия наблюдения</h2>
                <ul className="mt-3 list-disc space-y-2 pl-5 text-sm">
                  <li>
                    Учитывается большая сопоставимая сумма агрегированного
                    отчёта и SKU-сверки за один день; суммы не складываются.
                  </li>
                  <li>
                    Отсутствие данных не считается нулевым расходом. История до
                    подключения наблюдения не восстанавливается.
                  </li>
                  <li>
                    Расписание и отчётный день: Europe/Moscow (UTC+3). Интервал
                    чтения задаётся для кампании от 10 минут; квоты могут
                    отложить запрос.
                  </li>
                  <li>
                    Обновление экрана читает сохранённые данные сервиса и не
                    запускает новый отчёт Ozon.
                  </li>
                  <li>
                    Суточный лимит наблюдения отличается от недельного бюджета
                    площадки. Бюджет Ozon здесь не изменяется.
                  </li>
                  <li>
                    Команды запуска и остановки рекламы в этом выпуске
                    недоступны.
                  </li>
                </ul>
                <h3 className="mt-4 font-medium">Подключения Ozon</h3>
                {accounts.length === 0 ? (
                  <p className="mt-2 text-sm">Нет подключений Ozon.</p>
                ) : (
                  <ul className="mt-2 space-y-1 text-sm">
                    {accounts
                      .filter((a) => account === '' || a.id === account)
                      .map((a) => (
                        <li key={a.id}>
                          {a.label} ·{' '}
                          {a.available
                            ? 'рекламный ключ подключён'
                            : 'проверьте рекламный ключ и права'}
                        </li>
                      ))}
                  </ul>
                )}
                <Link className="mt-3 inline-block" to={`${root}/connections`}>
                  Управлять подключениями
                </Link>
              </Card>
            )}
          </>
        )}
      </div>
      {TABS.filter((item) => item.id !== tab).map((item) => (
        <div
          key={item.id}
          role="tabpanel"
          id={`ads-panel-${item.id}`}
          aria-labelledby={`ads-tab-${item.id}`}
          hidden
        />
      ))}
    </div>
  )
}
