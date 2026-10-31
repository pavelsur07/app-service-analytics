import { describe, expect, it } from 'vitest'
import { netPerformanceFixture } from '../../../../tests/fixtures/salesPerformance'
import { buildNetForecastStatusPresentation } from './netForecastStatusPresentation'

const cohort = netPerformanceFixture.skus[0]?.points[0]?.period.cohort
if (cohort == null) throw new Error('Missing net forecast fixture')

const pending = 'Публикация предварительного прогноза пока не включена.'

describe('net forecast status presentation', () => {
  it.each([null, undefined])(
    'explains absent cohort %s without inventing zero',
    (value) => {
      expect(buildNetForecastStatusPresentation(value)).toEqual({
        status: 'Нет оценки',
        tone: 'warning',
        messages: ['Продажи и выручка: Нет полных данных для прогноза продаж.'],
      })
    },
  )

  it('keeps confirmed quality and scope messages in their existing order', () => {
    expect(buildNetForecastStatusPresentation(cohort)).toEqual({
      status: 'Есть оценка',
      tone: 'neutral',
      messages: [
        'Предварительный прогноз. Точность ещё не проверена.',
        'Оценка по этим данным выполнялась.',
        'Данные оценки: SKU, кабинет.',
      ],
    })
  })

  it('treats server zeros as available without mutating the cohort', () => {
    const value = {
      ...cohort,
      expectedNetSalesQuantity: '0.000',
      expectedNetRevenue: { minor: '0', currency: 'KWD', fractionDigits: 3 },
    }
    const before = structuredClone(value)
    const result = buildNetForecastStatusPresentation(value)
    expect(result.status).toBe('Есть оценка')
    expect(result.tone).toBe('neutral')
    expect(value).toEqual(before)
    expect(buildNetForecastStatusPresentation(value)).toEqual(result)
  })

  it('explains missing quantity independently of a valid signed exact amount', () => {
    const result = buildNetForecastStatusPresentation({
      ...cohort,
      expectedNetSalesQuantity: null,
      netForecastUnavailableReason: 'source_history_unavailable',
      expectedNetRevenue: {
        minor: '-9223372036854775808',
        currency: 'CLF',
        fractionDigits: 4,
      },
    })
    expect(result.status).toBe('Частично')
    expect(result.messages[0]).toBe(
      'Количество: История наблюдений для прогноза продаж недоступна.',
    )
    expect(
      result.messages.some((message) => message.startsWith('Выручка:')),
    ).toBe(false)
  })

  it('explains invalid money while retaining available quantity', () => {
    const result = buildNetForecastStatusPresentation({
      ...cohort,
      expectedNetRevenue: { minor: '01', currency: 'RUB', fractionDigits: 2 },
    })
    expect(result.status).toBe('Частично')
    expect(result.messages[0]).toBe('Выручка: Прогноз выручки недоступен.')
  })

  it('rejects malformed quantity without hiding a valid zero revenue', () => {
    const result = buildNetForecastStatusPresentation({
      ...cohort,
      expectedNetSalesQuantity: '1.2',
      expectedNetRevenue: { minor: '0', currency: 'RUB', fractionDigits: 2 },
    })
    expect(result.status).toBe('Частично')
    expect(result.messages[0]).toBe('Количество: Прогноз продаж недоступен.')
  })

  it('shows a shared pending explanation once across values and quality', () => {
    const result = buildNetForecastStatusPresentation({
      ...cohort,
      expectedNetSalesQuantity: null,
      expectedNetRevenue: null,
      netForecastUnavailableReason: 'validation_pending',
      netForecastMoneyUnavailableReason: 'validation_pending',
      netForecastQuality: {
        validationStatus: 'validation_pending',
        assessmentStatus: 'not_assessed',
        sourceUnavailableReason: 'validation_pending',
        sourceMoneyUnavailableReason: 'validation_pending',
        componentReasons: ['validation_pending', 'validation_pending'],
        trainingScopes: [],
      },
    })
    expect(result).toEqual({
      status: 'Нет оценки',
      tone: 'warning',
      messages: [
        `Продажи и выручка: ${pending}`,
        'Оценка по этим данным не выполнялась.',
      ],
    })
  })

  it('keeps distinct local and quality reasons without repeating source reasons', () => {
    const result = buildNetForecastStatusPresentation({
      ...cohort,
      expectedNetSalesQuantity: null,
      expectedNetRevenue: null,
      netForecastUnavailableReason: 'source_history_unavailable',
      netForecastMoneyUnavailableReason: 'money_price_missing',
      netForecastQuality: {
        validationStatus: 'validation_pending',
        assessmentStatus: 'not_assessed',
        sourceUnavailableReason: 'source_history_unavailable',
        sourceMoneyUnavailableReason: 'money_price_missing',
        componentReasons: ['insufficient_sample', 'insufficient_sample'],
        trainingScopes: ['sku'],
      },
    })
    expect(result.messages).toEqual([
      'Количество: История наблюдений для прогноза продаж недоступна.',
      'Выручка: Нет цены исходной продажи для оценки выручки за вычетом возвратов.',
      pending,
      'Оценка по этим данным не выполнялась.',
      'Недостаточно пригодных наблюдений для оценки.',
      'Данные оценки: SKU.',
    ])
  })

  it('preserves generic wording for unknown shared codes and unknown quality scopes', () => {
    const result = buildNetForecastStatusPresentation({
      ...cohort,
      expectedNetSalesQuantity: null,
      expectedNetRevenue: null,
      netForecastUnavailableReason: 'unrecognized',
      netForecastMoneyUnavailableReason: 'unrecognized',
      netForecastQuality: {
        validationStatus: 'validation_pending',
        assessmentStatus: 'not_assessed',
        componentReasons: ['another_unknown', 'unrecognized'],
        trainingScopes: ['future_scope'],
      },
    })
    expect(result.messages).toEqual([
      'Продажи и выручка: Прогноз продаж недоступен.',
      pending,
      'Оценка по этим данным не выполнялась.',
      'Данные оценки: неизвестная область.',
    ])
  })
})
