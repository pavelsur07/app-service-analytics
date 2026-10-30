import { Trash2 } from 'lucide-react'
import type { components } from '../../../api/schema'
import { ApiError } from '../../../api/ApiError'
import { useRemoveSalesPlanSku } from '../model/useSalesPlans'

type SalesPlan = components['schemas']['SalesPlanResponse']

/**
 * SKU убирается из черновика целиком (ADR-040 п. 8) — после
 * подтверждения: ручные правки его дней пропадут с экрана, хотя
 * останутся в журнале.
 */
export function SalesPlanRemoveSkuButton({
  companyId,
  plan,
  marketplaceSku,
}: {
  companyId: string
  plan: SalesPlan
  marketplaceSku: string
}) {
  const removeSku = useRemoveSalesPlanSku(companyId, plan.id)
  const failure =
    removeSku.error === null
      ? null
      : removeSku.error instanceof ApiError &&
          removeSku.error.code === 'version_conflict'
        ? 'План изменил кто-то ещё — он перечитан, повторите.'
        : removeSku.error instanceof ApiError
          ? removeSku.error.message
          : 'Не удалось убрать.'

  return (
    <>
      <button
        className="text-text-muted hover:text-negative-text"
        type="button"
        aria-label={`Убрать ${marketplaceSku} из плана`}
        disabled={removeSku.isPending}
        title="Убрать из плана"
        onClick={() => {
          if (window.confirm(`Убрать ${marketplaceSku} из плана?`)) {
            removeSku.mutate({ version: plan.version, marketplaceSku })
          }
        }}
      >
        <Trash2 aria-hidden="true" size={14} />
      </button>
      {failure !== null && (
        <span className="text-xs font-normal text-negative-text" role="alert">
          {failure}
        </span>
      )}
    </>
  )
}
