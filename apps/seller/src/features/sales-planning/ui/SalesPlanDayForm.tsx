import { useState } from 'react'
import type { components } from '../../../api/schema'
import { Button, Input } from '../../../../../../packages/ui/src'
import { ApiError } from '../../../api/ApiError'
import { parseAmountToMinor } from '../../../shared/lib/parseAmountToMinor'
import { parseQuantity } from '../lib/salesPlan'
import { useChangeSalesPlanDay } from '../model/useSalesPlans'
import type { PickedDay } from './salesPlanEditorTypes'

type SalesPlan = components['schemas']['SalesPlanResponse']

/**
 * Правка одного дня (ADR-040 п. 7): акция с другой ценой, выходной.
 * Цена необязательна — пустая оставляет у дня прежнюю: вывести её
 * делением выручки на штуки значило бы считать деньги в компоненте (§10).
 */
export function SalesPlanDayForm({
  companyId,
  plan,
  picked,
  onClose,
}: {
  companyId: string
  plan: SalesPlan
  picked: PickedDay
  onClose: () => void
}) {
  const [quantity, setQuantity] = useState(String(picked.quantity))
  const [price, setPrice] = useState('')
  const changeDay = useChangeSalesPlanDay(companyId, plan.id)

  const parsedQuantity = parseQuantity(quantity)
  const parsedPrice = price.trim() === '' ? null : parseAmountToMinor(price)
  const priceInvalid =
    price.trim() !== '' && (parsedPrice === null || parsedPrice === 0)
  const conflict =
    changeDay.error instanceof ApiError &&
    changeDay.error.code === 'version_conflict'
  // После конфликта форма держит устаревший снимок: повтор возможен
  // только из заново открытого дня — с новыми штуками и версией.
  const ready = parsedQuantity !== null && !priceInvalid && !conflict

  return (
    <form
      className="mt-4 flex flex-col gap-3 border-t border-border-subtle pt-4"
      onSubmit={(event) => {
        event.preventDefault()
        if (!ready) {
          return
        }

        changeDay.mutate(
          {
            version: picked.version,
            marketplaceSku: picked.marketplaceSku,
            date: picked.date,
            quantity: parsedQuantity,
            priceMinor: parsedPrice,
          },
          { onSuccess: onClose },
        )
      }}
    >
      <h2 className="font-semibold">
        День {picked.date}, SKU {picked.marketplaceSku}
      </h2>
      <div className="flex flex-wrap gap-3">
        <Input
          error={
            parsedQuantity === null ? 'Целое число до 1 000 000' : undefined
          }
          inputMode="numeric"
          label="Штук в этот день"
          value={quantity}
          onChange={(event) => {
            setQuantity(event.target.value)
          }}
        />
        <Input
          error={priceInvalid ? 'Сумма больше нуля' : undefined}
          hint="Пусто — цена дня не изменится"
          inputMode="decimal"
          label="РРЦ в этот день, ₽"
          placeholder="1299,90"
          value={price}
          onChange={(event) => {
            setPrice(event.target.value)
          }}
        />
      </div>

      {changeDay.error !== null && (
        <p className="text-sm text-negative-text" role="alert">
          {conflict
            ? 'План изменил кто-то ещё — он перечитан. Откройте день заново и повторите правку.'
            : changeDay.error instanceof ApiError
              ? changeDay.error.message
              : 'Не удалось сохранить день.'}
        </p>
      )}

      <div className="flex gap-2">
        <Button
          type="submit"
          size="compact"
          disabled={!ready || changeDay.isPending}
        >
          Сохранить день
        </Button>
        <Button type="button" variant="ghost" size="compact" onClick={onClose}>
          Отмена
        </Button>
      </div>
    </form>
  )
}
