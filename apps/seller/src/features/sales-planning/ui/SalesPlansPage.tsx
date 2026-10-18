import { useEffect, useState } from 'react'
import { CircleX, ClipboardList, Plus } from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router'
import { ApiError } from '../../../api/ApiError'
import {
  Badge,
  Button,
  Card,
  Input,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import { useConnections } from '../../../shared/model/useConnections'
import { periodProblem, statusLabel } from '../lib/salesPlan'
import { useCreateSalesPlan, useSalesPlans } from '../model/useSalesPlans'

// Валюта плана одна (ADR-040 п. 6). Подключения сегодня только Ozon,
// и экран, как и себестоимость, отправляет рубли.
const CURRENCY = 'RUB'

/**
 * Планы продаж компании (ADR-040): список с итогами и создание черновика.
 * SKU вводятся на экране плана — у черновика своя версия, и ввод идёт
 * уже по ней.
 */
export function SalesPlansPage() {
  const { companyId } = useParams<{ companyId: string }>()

  // Ключ по компании: переключатель компании оставляет тот же маршрут,
  // и без ключа курсор страницы и выбранное подключение компании A
  // уехали бы в запросы компании B.
  return <SalesPlansScreen key={companyId ?? ''} />
}

function SalesPlansScreen() {
  const navigate = useNavigate()
  const { companyId } = useParams<{ companyId: string }>()
  const [cursorStack, setCursorStack] = useState<(string | null)[]>([null])
  const cursor = cursorStack[cursorStack.length - 1] ?? null
  const [formOpen, setFormOpen] = useState(false)

  const plans = useSalesPlans(companyId ?? '', cursor)

  useEffect(() => {
    if (plans.error instanceof ApiError && plans.error.status === 403) {
      void navigate('/companies', { replace: true })
    }
  }, [plans.error, navigate])

  if (companyId === undefined) {
    return null
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between gap-4">
        <h1 className="text-xl font-semibold">План продаж</h1>
        {!formOpen && (
          <Button
            type="button"
            size="compact"
            onClick={() => {
              setFormOpen(true)
            }}
          >
            <Plus aria-hidden="true" size={16} />
            Новый план
          </Button>
        )}
      </div>

      <p className="text-sm text-text-muted">
        План — штуки и РРЦ по каждому SKU на каждый день периода. Период
        выбирается под задачу: от одного дня до сезона в 184 дня.
      </p>

      {formOpen && (
        <CreatePlanForm
          companyId={companyId}
          onCancel={() => {
            setFormOpen(false)
          }}
          onCreated={(planId) => {
            void navigate(`/companies/${companyId}/sales-plans/${planId}`)
          }}
        />
      )}

      {plans.status === 'pending' && (
        <Card>
          <div className="h-32 animate-pulse rounded bg-border-subtle" />
        </Card>
      )}

      {plans.status === 'error' && (
        <Card tone="negative">
          <StatusPanel
            description={
              plans.error instanceof Error
                ? plans.error.message
                : 'Неизвестная ошибка'
            }
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Не удалось загрузить планы"
            tone="negative"
          />
        </Card>
      )}

      {plans.status === 'success' && plans.data.items.length === 0 && (
        <Card>
          <StatusPanel
            description="Создайте первый план: выберите подключение и период."
            icon={<ClipboardList aria-hidden="true" size={20} />}
            title="Планов пока нет"
            tone="neutral"
          />
        </Card>
      )}

      {plans.status === 'success' && plans.data.items.length > 0 && (
        <Card>
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border-default text-xs text-text-muted">
                <th className="px-4 py-2 text-left font-medium" scope="col">
                  План
                </th>
                <th className="px-4 py-2 text-left font-medium" scope="col">
                  Период
                </th>
                <th className="px-4 py-2 text-left font-medium" scope="col">
                  Статус
                </th>
                <th className="px-4 py-2 text-right font-medium" scope="col">
                  SKU
                </th>
                <th className="px-4 py-2 text-right font-medium" scope="col">
                  Штуки
                </th>
                <th className="px-4 py-2 text-right font-medium" scope="col">
                  Выручка
                </th>
              </tr>
            </thead>
            <tbody>
              {plans.data.items.map((plan) => (
                <tr key={plan.id} className="border-b border-border-subtle">
                  <td className="px-4 py-2">
                    <Link
                      className="font-medium text-accent-default hover:text-accent-hover hover:underline"
                      to={`/companies/${companyId}/sales-plans/${plan.id}`}
                    >
                      {plan.name}
                    </Link>
                  </td>
                  <td className="px-4 py-2 tabular-nums">
                    {plan.dateFrom} — {plan.dateTo}
                  </td>
                  <td className="px-4 py-2">
                    <Badge
                      tone={plan.status === 'approved' ? 'positive' : 'neutral'}
                    >
                      {statusLabel(plan.status)}
                    </Badge>
                  </td>
                  <td className="px-4 py-2 text-right tabular-nums">
                    {plan.skuCount}
                  </td>
                  <td className="px-4 py-2 text-right tabular-nums">
                    {plan.totalQuantity}
                  </td>
                  <td className="px-4 py-2 text-right tabular-nums">
                    {formatMinorAmount(plan.revenueMinor, plan.currency, {
                      wholeUnits: true,
                    })}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {((plans.data.nextCursor ?? null) !== null ||
            cursorStack.length > 1) && (
            <div className="mt-4 flex items-center justify-end gap-2">
              <Button
                type="button"
                variant="secondary"
                size="compact"
                disabled={cursorStack.length <= 1}
                onClick={() => {
                  setCursorStack((stack) =>
                    stack.length > 1 ? stack.slice(0, -1) : stack,
                  )
                }}
              >
                Назад
              </Button>
              <Button
                type="button"
                variant="secondary"
                size="compact"
                disabled={(plans.data.nextCursor ?? null) === null}
                onClick={() => {
                  const next = plans.data.nextCursor ?? null
                  if (next !== null) {
                    setCursorStack((stack) => [...stack, next])
                  }
                }}
              >
                Дальше
              </Button>
            </div>
          )}
        </Card>
      )}
    </div>
  )
}

function CreatePlanForm({
  companyId,
  onCancel,
  onCreated,
}: {
  companyId: string
  onCancel: () => void
  onCreated: (planId: string) => void
}) {
  const connections = useConnections(companyId)
  const createPlan = useCreateSalesPlan(companyId)
  const [accountId, setAccountId] = useState('')
  const [name, setName] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')

  // Отозванное подключение больше не синхронизируется (ADR-007): план
  // на него сервер не заведёт, и предлагать его незачем.
  const usable = (connections.data?.connections ?? []).filter(
    (connection) => connection.state !== 'revoked',
  )
  const selectedAccount = accountId !== '' ? accountId : (usable[0]?.id ?? '')
  const problem =
    dateFrom === '' && dateTo === '' ? null : periodProblem(dateFrom, dateTo)
  const ready =
    selectedAccount !== '' &&
    name.trim() !== '' &&
    dateFrom !== '' &&
    dateTo !== '' &&
    problem === null

  return (
    <Card>
      <form
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          if (!ready) {
            return
          }

          createPlan.mutate(
            {
              marketplaceAccountId: selectedAccount,
              name: name.trim(),
              dateFrom,
              dateTo,
              currency: CURRENCY,
            },
            {
              onSuccess: (created) => {
                onCreated(created.id)
              },
            },
          )
        }}
      >
        <h2 className="font-semibold">Новый план</h2>

        <label className="flex flex-col gap-1 text-sm">
          Подключение
          <select
            className="h-9 rounded-md border border-border-default bg-surface-raised px-2"
            value={selectedAccount}
            onChange={(event) => {
              setAccountId(event.target.value)
            }}
          >
            {usable.map((connection) => (
              <option key={connection.id} value={connection.id}>
                {connection.marketplace} · {connection.externalShopId}
              </option>
            ))}
          </select>
        </label>

        <Input
          label="Название"
          maxLength={200}
          placeholder="Сезон осень–зима"
          value={name}
          onChange={(event) => {
            setName(event.target.value)
          }}
        />

        <div className="flex flex-wrap gap-3">
          <Input
            label="С"
            type="date"
            value={dateFrom}
            onChange={(event) => {
              setDateFrom(event.target.value)
            }}
          />
          <Input
            error={problem ?? undefined}
            label="По (включительно)"
            type="date"
            value={dateTo}
            onChange={(event) => {
              setDateTo(event.target.value)
            }}
          />
        </div>

        {createPlan.error !== null && (
          <p className="text-sm text-negative-text" role="alert">
            {createPlan.error instanceof ApiError
              ? createPlan.error.message
              : 'Не удалось создать план.'}
          </p>
        )}

        <div className="flex gap-2">
          <Button
            type="submit"
            size="compact"
            disabled={!ready || createPlan.isPending}
          >
            Создать черновик
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="compact"
            onClick={onCancel}
          >
            Отмена
          </Button>
        </div>
      </form>
    </Card>
  )
}
