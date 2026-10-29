import { describe, expect, it } from 'vitest'
import {
  formatNetQuantity,
  formatGoalDeviation,
  goalForecastReason,
  netForecastReason,
  netForecastStatus,
} from './netForecastPresentation'

describe('net forecast presentation', () => {
  it('separates quantity units without losing signed or large server decimals', () => {
    expect(
      formatNetQuantity('900719925474099312345.125', { withUnit: false }),
    ).toBe('900719925474099312345,125')
    expect(formatGoalDeviation('-0.333', { withUnit: false })).toBe('-0,333')
  })
  it.each([
    ['90.125', '90,125 шт.'],
    ['0.000', '0,000 шт.'],
    ['900719925474099312345.125', '900719925474099312345,125 шт.'],
  ])('preserves server decimal quantity %s', (value, expected) => {
    expect(formatNetQuantity(value)).toBe(expected)
  })

  it.each(['', '-1.000', '1.2', '1e3', '1.0000'])(
    'does not invent a quantity for %j',
    (value) => {
      expect(formatNetQuantity(value)).toBe('—')
    },
  )

  it('explains publication pending independently of source assessment', () => {
    expect(netForecastReason('validation_pending', 'quantity')).toContain(
      'Проверка качества прогноза ещё не завершена',
    )
    expect(netForecastStatus('not_assessed', 'assessment')).toBe(
      'Оценка по этим данным не выполнялась.',
    )
    expect(netForecastReason('validation_pending', 'quantity')).not.toContain(
      'истории',
    )
  })

  it('translates actual source and money reasons centrally', () => {
    expect(netForecastReason('money_price_missing', 'money')).toContain('цены')
    expect(netForecastReason('returns_coverage_missing', 'quantity')).toContain(
      'возврат',
    )
    expect(netForecastReason('money_currency_mismatch', 'money')).toContain(
      'валют',
    )
    expect(netForecastReason('query_budget_exceeded', 'quantity')).toContain(
      'лимит',
    )
  })

  it.each([
    null,
    undefined,
    'future_unknown_reason',
    'constructor',
    '__proto__',
  ])('gives an honest kind-specific fallback for %s', (reason) => {
    expect(netForecastReason(reason, 'quantity')).toBe(
      'Нетто-прогноз недоступен.',
    )
    expect(netForecastReason(reason, 'money')).toBe('Нетто-выручка недоступна.')
  })

  it('never turns unfamiliar validation or assessment into success', () => {
    expect(netForecastStatus('unexpected', 'validation')).toBe(
      'Статус проверки качества неизвестен.',
    )
    expect(netForecastStatus('unexpected', 'assessment')).toBe(
      'Статус оценки неизвестен.',
    )
    expect(netForecastStatus('release_approved', 'validation')).toBe(
      'Публикация численной оценки разрешена.',
    )
    expect(netForecastStatus('assessed', 'assessment')).toBe(
      'Оценка по этим данным выполнялась.',
    )
  })
})

describe('goal forecast presentation', () => {
  it.each([
    ['-100.000', '-100,000 шт.'],
    ['0.000', '0,000 шт.'],
    ['900719925474099312345.125', '900719925474099312345,125 шт.'],
  ])('preserves signed decimal %s', (value, expected) => {
    expect(formatGoalDeviation(value)).toBe(expected)
  })
  it.each(['', '1.2', '+1.000', '1e3', 'NaN'])(
    'rejects malformed signed decimal %s',
    (value) => {
      expect(formatGoalDeviation(value)).toBe('—')
    },
  )
  it('keeps orders goal reasons independent of net labels and raw reason codes', () => {
    for (const reason of [
      null,
      'future_t1_unavailable',
      'constructor',
      '__proto__',
      'source_history_unavailable',
      'quantity_unavailable',
    ]) {
      expect(goalForecastReason(reason)).not.toContain('нетто')
      expect(goalForecastReason(reason)).not.toContain('future_t1')
    }
    expect(goalForecastReason('validation_pending')).toContain(
      'Проверка качества',
    )
    expect(goalForecastReason('goal_component_mismatch')).toContain(
      'противоречивы',
    )
  })
})
