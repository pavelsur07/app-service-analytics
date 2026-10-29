import type { components } from '../../../api/schema'
import { formatMinorAmountExact } from '../../../shared/lib/formatMinorAmountExact'
import {
  formatNetQuantity,
  netForecastReason,
  netForecastStatus,
} from '../lib/netForecastPresentation'

export function PerformanceNetForecast({
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
    <div className="flex flex-col gap-2 text-sm">
      <dl className="tabular-nums" aria-label="Нетто-прогноз">
        <dt>Ожидаемые нетто-продажи</dt>
        <dd className="font-semibold">{quantity}</dd>
        {sharedReason === null && quantityReason !== null && (
          <dd className="text-text-muted">{quantityReason}</dd>
        )}
        <dt className="text-text-muted">Ожидаемая нетто-выручка</dt>
        <dd>{revenue}</dd>
        {sharedReason === null && moneyReason !== null && (
          <dd className="text-text-muted">{moneyReason}</dd>
        )}
      </dl>
      {sharedReason !== null && (
        <p className="text-text-muted">Продажи и выручка: {sharedReason}</p>
      )}
      {quality != null && (
        <div className="text-text-muted">
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
        </div>
      )}
    </div>
  )
}
