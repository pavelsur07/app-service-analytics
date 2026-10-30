import type { ReactNode } from 'react'
import type { components } from '../../../api/schema'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import { formatMinorAmountExact } from '../../../shared/lib/formatMinorAmountExact'

type Values = components['schemas']['PerformanceValuesResponse']
export interface PerformanceColumn {
  key: string
  value: Values
}
type Money =
  | Values['planRevenueRrp']
  | NonNullable<NonNullable<Values['cohort']>['expectedNetRevenue']>

export function PerformanceMetricRow({
  label,
  unit,
  columns,
  children,
  description,
}: {
  label: string
  unit?: string
  columns: readonly PerformanceColumn[]
  children: (value: Values, index: number) => ReactNode
  description?: ReactNode
}) {
  return (
    <tr className="border-b border-border-subtle">
      <th
        scope="row"
        className="sticky left-0 z-10 w-48 min-w-48 max-w-48 bg-surface-raised px-3 py-2 text-left align-top font-normal sm:w-64 sm:min-w-64 sm:max-w-64"
      >
        <span>{label}</span>
        {unit !== undefined && (
          <span className="text-text-muted">, {unit}</span>
        )}
        {description !== undefined && (
          <details className="mt-1 text-xs text-text-muted">
            <summary className="cursor-pointer">О показателе</summary>
            <div className="mt-2 font-normal">{description}</div>
          </details>
        )}
      </th>
      {columns.map((column, index) => (
        <td
          key={column.key}
          className="px-3 py-2 text-right align-top wrap-anywhere whitespace-normal tabular-nums"
        >
          {children(column.value, index)}
        </td>
      ))}
    </tr>
  )
}

export function PerformanceValue({
  children,
  reason,
}: {
  children: ReactNode
  reason?: string | null
}) {
  if (reason == null) return children
  return (
    <details className="text-text-muted">
      <summary className="cursor-help list-none underline decoration-dotted underline-offset-4 focus-visible:outline-2 focus-visible:outline-accent-default">
        {children}
      </summary>
      <p className="mt-2 max-w-full text-left text-xs wrap-anywhere whitespace-normal">
        {reason}
      </p>
    </details>
  )
}

export function PerformanceMoneyRow({
  label,
  columns,
  currency,
  amount,
}: {
  label: string
  columns: readonly PerformanceColumn[]
  currency: string
  amount: (value: Values) => Money | null | undefined
}) {
  const currencies = [
    ...new Set(
      columns.flatMap(({ value }) => {
        const money = amount(value)
        return money == null ? [] : [money.currency]
      }),
    ),
  ]
  if (currencies.length === 0) currencies.push(currency)
  return (
    <>
      {currencies.map((code) => (
        <PerformanceMetricRow
          key={code}
          label={label}
          unit={code === 'RUB' ? 'р.' : code || 'валюта неизвестна'}
          columns={columns}
        >
          {(value) => {
            const money = amount(value)
            if (money == null || money.currency !== code) return '—'
            return 'fractionDigits' in money
              ? formatMinorAmountExact(
                  money.minor,
                  money.currency,
                  money.fractionDigits,
                  { withCurrency: false },
                )
              : formatMinorAmount(money.minor, money.currency, {
                  withCurrency: false,
                })
          }}
        </PerformanceMetricRow>
      ))}
    </>
  )
}
