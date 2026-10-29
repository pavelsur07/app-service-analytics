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

export function formatGoalDeviation(value: string): string {
  return /^-?[0-9]+\.[0-9]{3}$/.test(value)
    ? `${value.replace('.', ',')} шт.`
    : '—'
}

const goalReasons: Readonly<Record<string, string>> = {
  validation_pending: reasons.validation_pending ?? '',
  source_history_unavailable:
    'История наблюдений для прогноза цели недоступна.',
  orders_coverage_unavailable:
    'Нет полного покрытия заказов для прогноза цели.',
  quantity_unavailable: 'Количество для прогноза цели недоступно.',
  future_t1_unavailable: 'Не удалось оценить будущие отмены до передачи.',
  gross_probability_unavailable:
    'Недостаточно наблюдений для оценки будущих отмен.',
  goal_component_mismatch:
    'Исходные количества для прогноза цели противоречивы.',
  goal_attainment_overflow:
    'Выполнение цели выходит за поддерживаемый диапазон.',
  input_budget_exceeded: reasons.input_budget_exceeded ?? '',
  query_budget_exceeded: reasons.query_budget_exceeded ?? '',
  unknown_outcome: reasons.unknown_outcome ?? '',
  return_risk_unavailable: reasons.return_risk_unavailable ?? '',
  open_retention_unavailable: reasons.open_retention_unavailable ?? '',
}

export function goalForecastReason(reason: string | null | undefined): string {
  return (
    (reason != null && Object.hasOwn(goalReasons, reason)
      ? goalReasons[reason]
      : undefined) ?? 'Прогноз метрики цели недоступен.'
  )
}
