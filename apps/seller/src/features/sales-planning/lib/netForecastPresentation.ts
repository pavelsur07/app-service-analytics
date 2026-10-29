export function formatNetQuantity(value: string): string {
  return typeof value === 'string' && /^[0-9]+\.[0-9]{3}$/.test(value)
    ? `${value.replace('.', ',')} шт.`
    : '—'
}

const reasons: Readonly<Record<string, string>> = {
  validation_pending:
    'Проверка качества прогноза ещё не завершена. Численная оценка пока не публикуется.',
  source_history_unavailable:
    'История наблюдений для нетто-прогноза недоступна.',
  postings_coverage_missing: 'Нет полного покрытия загрузок заказов.',
  returns_coverage_missing: 'Нет полного покрытия загрузок возвратов.',
  delivery_observation_unknown: 'Нет подтверждённого наблюдения доставки.',
  ambiguous_delivery_allocation:
    'Не удалось однозначно связать возвраты с доставками.',
  insufficient_sample: 'Недостаточно пригодных наблюдений для оценки.',
  unknown_outcome: 'Есть заказы с неизвестным исходом.',
  contradictory_quantity: 'Количество в исходных наблюдениях противоречиво.',
  maturity_unavailable: 'Нет данных о сроках завершения заказов.',
  input_budget_exceeded: 'Превышен лимит данных для оценки.',
  query_budget_exceeded: 'Превышен лимит обработки данных для оценки.',
  valuation_unavailable: 'Оценка выручки по ценам исходных продаж недоступна.',
  money_price_missing: 'Нет цены исходной продажи для оценки нетто-выручки.',
  money_currency_missing: 'Неизвестна валюта исходной продажи.',
  money_currency_mismatch: 'В исходных продажах различаются валюты.',
  money_overflow: 'Сумма выходит за поддерживаемый диапазон оценки.',
  money_precision_exceeded: 'Оценка выручки превышает поддерживаемую точность.',
  money_input_invalid: 'Некорректные исходные данные для оценки выручки.',
  quantity_unavailable: 'Ожидаемое нетто-количество недоступно.',
  return_risk_unavailable: 'Не удалось оценить будущие клиентские возвраты.',
  open_retention_unavailable: 'Не удалось оценить итог открытых заказов.',
}

export function netForecastReason(
  reason: string | null | undefined,
  kind: 'quantity' | 'money',
): string {
  return (
    (reason != null && Object.hasOwn(reasons, reason)
      ? reasons[reason]
      : undefined) ??
    (kind === 'quantity'
      ? 'Нетто-прогноз недоступен.'
      : 'Нетто-выручка недоступна.')
  )
}

export function netForecastStatus(
  status: string | null | undefined,
  kind: 'validation' | 'assessment',
): string {
  if (kind === 'validation') {
    if (status === 'validation_pending') return reasons.validation_pending ?? ''
    if (status === 'release_approved')
      return 'Публикация численной оценки разрешена.'
    return 'Статус проверки качества неизвестен.'
  }
  if (status === 'not_assessed') return 'Оценка по этим данным не выполнялась.'
  if (status === 'assessed') return 'Оценка по этим данным выполнялась.'
  return 'Статус оценки неизвестен.'
}
