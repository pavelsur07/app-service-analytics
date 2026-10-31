import type { components } from '../../../api/schema'
import { formatMinorAmountExact } from '../../../shared/lib/formatMinorAmountExact'
import {
  formatNetQuantity,
  netForecastReason,
  netForecastStatus,
} from './netForecastPresentation'

interface NetForecastStatusPresentation {
  readonly status: 'Есть оценка' | 'Частично' | 'Нет оценки'
  readonly tone: 'neutral' | 'warning'
  readonly messages: readonly string[]
}

export function buildNetForecastStatusPresentation(
  cohort: components['schemas']['PerformanceCohortResponse'] | null | undefined,
): NetForecastStatusPresentation {
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
  const missingCohort = 'Нет полных данных для прогноза продаж.'
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
  const messages: string[] = []
  if (sharedReason === null && quantityReason !== null)
    messages.push(`Количество: ${quantityReason}`)
  if (sharedReason === null && moneyReason !== null)
    messages.push(`Выручка: ${moneyReason}`)
  if (sharedReason !== null) messages.push(`Продажи и выручка: ${sharedReason}`)
  if (quality != null) {
    messages.push(...qualityMessages)
    if (quality.trainingScopes.length > 0)
      messages.push(
        `Данные оценки: ${quality.trainingScopes
          .map((scope) =>
            scope === 'sku'
              ? 'SKU'
              : scope === 'account'
                ? 'кабинет'
                : 'неизвестная область',
          )
          .join(', ')}.`,
      )
  }
  return {
    status:
      quantityReason === null && moneyReason === null
        ? 'Есть оценка'
        : quantityReason === null || moneyReason === null
          ? 'Частично'
          : 'Нет оценки',
    tone:
      quantityReason === null && moneyReason === null ? 'neutral' : 'warning',
    messages,
  }
}
