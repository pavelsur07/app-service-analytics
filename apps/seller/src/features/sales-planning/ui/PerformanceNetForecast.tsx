import type { components } from '../../../api/schema'
import {
  PerformanceMetricRow,
  PerformanceMoneyRow,
  type PerformanceColumn,
} from './PerformanceMetricRow'
import { formatMinorAmountExact } from '../../../shared/lib/formatMinorAmountExact'
import {
  formatNetQuantity,
  netForecastReason,
  netForecastStatus,
} from '../lib/netForecastPresentation'

export function PerformanceNetForecast({
  columns,
  currency,
}: {
  columns: readonly PerformanceColumn[]
  currency: string
}) {
  return (
    <>
      <PerformanceMetricRow
        label="Ожидаемые нетто-продажи"
        unit="шт."
        columns={columns}
      >
        {({ cohort }) =>
          cohort?.expectedNetSalesQuantity == null
            ? '—'
            : formatNetQuantity(cohort.expectedNetSalesQuantity, {
                withUnit: false,
              })
        }
      </PerformanceMetricRow>
      <PerformanceMoneyRow
        label="Ожидаемая нетто-выручка"
        columns={columns}
        currency={currency}
        amount={({ cohort }) => cohort?.expectedNetRevenue}
      />
      <PerformanceMetricRow
        label="Доступность нетто-прогноза"
        columns={columns}
      >
        {({ cohort }) => <PerformanceNetStatus cohort={cohort} />}
      </PerformanceMetricRow>
    </>
  )
}

function PerformanceNetStatus({
  cohort,
}: {
  cohort: components['schemas']['PerformanceCohortResponse'] | null | undefined
}) {
  const quantity =
    cohort?.expectedNetSalesQuantity == null
      ? '—'
      : formatNetQuantity(cohort.expectedNetSalesQuantity)
  const money = cohort?.expectedNetRevenue
  const revenue =
    money == null
      ? '—'
      : formatMinorAmountExact(
          money.minor,
          money.currency,
          money.fractionDigits,
        )
  const missingCohort = 'Нет полных данных для нетто-прогноза.'
  const quantityReason =
    quantity === '—'
      ? cohort == null
        ? missingCohort
        : netForecastReason(cohort.netForecastUnavailableReason, 'quantity')
      : null
  const moneyReason =
    revenue === '—'
      ? cohort == null
        ? missingCohort
        : netForecastReason(cohort.netForecastMoneyUnavailableReason, 'money')
      : null
  const sharedReason =
    quantityReason != null &&
    moneyReason != null &&
    (quantityReason === moneyReason ||
      (cohort?.netForecastUnavailableReason != null &&
        cohort.netForecastUnavailableReason ===
          cohort.netForecastMoneyUnavailableReason))
      ? quantityReason
      : null
  const shownMessages = new Set<string>()
  const shownCodes = new Set<string>()
  for (const [code, message] of [
    [cohort?.netForecastUnavailableReason, quantityReason],
    [cohort?.netForecastMoneyUnavailableReason, moneyReason],
  ] as const) {
    if (message != null) {
      shownMessages.add(message)
      if (code != null) shownCodes.add(code)
    }
  }
  const quality = cohort?.netForecastQuality
  const qualityMessages: string[] = []
  const addQuality = (text: string, code?: string, label?: string) => {
    if (shownMessages.has(text) || (code !== undefined && shownCodes.has(code)))
      return
    shownMessages.add(text)
    if (code !== undefined) shownCodes.add(code)
    qualityMessages.push(label === undefined ? text : `${label}: ${text}`)
  }
  if (quality != null) {
    addQuality(netForecastStatus(quality.validationStatus, 'validation'))
    addQuality(netForecastStatus(quality.assessmentStatus, 'assessment'))
    if (quality.sourceUnavailableReason != null)
      addQuality(
        netForecastReason(quality.sourceUnavailableReason, 'quantity'),
        quality.sourceUnavailableReason,
        'Источник количества',
      )
    if (quality.sourceMoneyUnavailableReason != null)
      addQuality(
        netForecastReason(quality.sourceMoneyUnavailableReason, 'money'),
        quality.sourceMoneyUnavailableReason,
        'Источник выручки',
      )
    for (const reason of quality.componentReasons)
      addQuality(netForecastReason(reason, 'quantity'), reason)
  }
  return (
    <details className="text-sm text-text-muted">
      <summary className="cursor-help focus-visible:outline-2 focus-visible:outline-accent-default">
        {quantityReason === null && moneyReason === null
          ? 'Есть оценка'
          : quantityReason === null || moneyReason === null
            ? 'Частичная оценка'
            : 'Нет оценки'}
      </summary>
      <div className="mt-2 max-w-64 text-left whitespace-normal">
        {sharedReason === null && quantityReason !== null && (
          <p>Количество: {quantityReason}</p>
        )}
        {sharedReason === null && moneyReason !== null && (
          <p>Выручка: {moneyReason}</p>
        )}
        {sharedReason !== null && <p>Продажи и выручка: {sharedReason}</p>}
        {quality != null && (
          <>
            {qualityMessages.map((message) => (
              <p key={message}>{message}</p>
            ))}
            {quality.trainingScopes.length > 0 && (
              <p>
                Данные оценки:{' '}
                {quality.trainingScopes
                  .map((scope) =>
                    scope === 'sku'
                      ? 'SKU'
                      : scope === 'account'
                        ? 'кабинет'
                        : 'неизвестная область',
                  )
                  .join(', ')}
                .
              </p>
            )}
          </>
        )}
      </div>
    </details>
  )
}
