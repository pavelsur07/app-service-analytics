import { useState } from 'react'
import { Button, Card } from '../../../../../../packages/ui/src'
import type { components } from '../../../api/schema'
import { SalesPlanLifecycleError } from './SalesPlanLifecycleError'
import { useSalesPlanLifecycle } from '../model/useSalesPlanLifecycle'

type Plan = components['schemas']['SalesPlanResponse']
type Metric = components['schemas']['SalesPlanMetric']

export function SalesPlanLifecycle({
  companyId,
  plan,
  onRevision,
}: {
  companyId: string
  plan: Plan
  onRevision: (id: string) => void
}) {
  const mutation = useSalesPlanLifecycle(companyId, plan.id)
  const [selection, setSelection] = useState<{
    metric: Metric
    version: number
  } | null>(null)
  const editable = plan.status === 'draft'
  const missingMetric = plan.metric == null
  const canChooseMetric =
    editable && (missingMetric || plan.versionNumber === 1)
  const metricLabel =
    plan.metric === 'retained_sales'
      ? 'Продажи за вычетом уже состоявшихся возвратов'
      : 'Заказы без отмен до передачи в доставку'
  return (
    <Card>
      <h2 className="font-semibold">Метрика цели и версия плана</h2>
      {canChooseMetric ? (
        <div className="mt-3 flex flex-col gap-3">
          <p className="text-sm text-text-secondary">
            Выберите, что означают плановые штуки. Исходные числа сохранятся.
          </p>
          <fieldset
            className="flex flex-col gap-2"
            disabled={mutation.isPending}
          >
            <legend>Что планируем</legend>
            {(
              [
                {
                  value: 'retained_sales',
                  label: 'Продажи за вычетом уже состоявшихся возвратов',
                },
                {
                  value: 'orders_excluding_pre_handover_cancellations',
                  label: 'Заказы без отмен до передачи в доставку',
                },
              ] as const
            ).map((option) => (
              <label
                key={option.value}
                className="flex items-center gap-2 text-sm"
              >
                <input
                  type="radio"
                  name="goal-metric"
                  checked={(selection?.metric ?? plan.metric) === option.value}
                  onChange={() => {
                    setSelection({
                      metric: option.value,
                      version: plan.version,
                    })
                  }}
                />
                {option.label}
              </label>
            ))}
          </fieldset>
          <div>
            <Button
              size="compact"
              disabled={selection === null || mutation.isPending}
              onClick={() => {
                if (selection !== null)
                  mutation.mutate(
                    { kind: 'metric', ...selection },
                    {
                      onSuccess: () => {
                        setSelection(null)
                      },
                    },
                  )
              }}
            >
              Сохранить смысл цели
            </Button>
          </div>
        </div>
      ) : (
        <p className="mt-2 text-sm text-text-secondary">
          {missingMetric
            ? 'Смысл старой цели ещё не выбран. Создайте новую версию и укажите метрику, сохранив числа.'
            : metricLabel}
        </p>
      )}
      {plan.versionNumber > 1 && (
        <p className="mt-2 text-sm text-text-muted">
          Новая версия не заменяет исходную числовую цель сезона.
        </p>
      )}
      {editable && plan.versionNumber === 1 && (
        <p className="mt-2 text-sm text-text-muted">
          После первого утверждения появится сравнение с исходной целью.
        </p>
      )}
      <div className="mt-3 flex flex-wrap gap-2">
        {editable && (
          <>
            <Button
              size="compact"
              disabled={
                missingMetric ||
                selection !== null ||
                plan.skuCount === 0 ||
                mutation.isPending
              }
              onClick={() => {
                mutation.mutate({ kind: 'approve', version: plan.version })
              }}
            >
              {plan.versionNumber === 1
                ? 'Утвердить исходную цель'
                : 'Утвердить новую версию'}
            </Button>
            <Button
              size="compact"
              variant="secondary"
              disabled={mutation.isPending}
              onClick={() => {
                if (window.confirm('Отклонить черновик? История сохранится.'))
                  mutation.mutate({ kind: 'discard', version: plan.version })
              }}
            >
              Отклонить черновик
            </Button>
          </>
        )}
        {plan.status === 'approved' && (
          <>
            <Button
              size="compact"
              disabled={mutation.isPending}
              onClick={() => {
                mutation.mutate(
                  { kind: 'revision', version: plan.version },
                  {
                    onSuccess: (result) => {
                      onRevision(result.id)
                    },
                  },
                )
              }}
            >
              Создать новую версию
            </Button>
            <Button
              size="compact"
              variant="secondary"
              disabled={mutation.isPending}
              onClick={() => {
                if (
                  window.confirm(
                    'Отменить план? История и исходная цель сохранятся.',
                  )
                )
                  mutation.mutate({ kind: 'cancel', version: plan.version })
              }}
            >
              Отменить план
            </Button>
          </>
        )}
      </div>
      {mutation.error !== null && (
        <SalesPlanLifecycleError
          error={mutation.error}
          onDismiss={() => {
            mutation.reset()
          }}
          onVersionReviewed={() => {
            mutation.reset()
            setSelection((current) =>
              current === null ? null : { ...current, version: plan.version },
            )
          }}
        />
      )}
    </Card>
  )
}
