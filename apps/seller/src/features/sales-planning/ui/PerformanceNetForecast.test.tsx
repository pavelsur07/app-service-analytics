import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import { netPerformanceFixture } from '../../../../tests/fixtures/salesPerformance'
import { PerformanceNetForecast as MetricRows } from './PerformanceNetForecast'
import { performanceColumns } from '../../../../tests/fixtures/performanceColumns'
import type { components } from '../../../api/schema'

function PerformanceNetForecast({
  cohort,
}: {
  cohort: components['schemas']['PerformanceCohortResponse'] | null | undefined
}) {
  return (
    <MetricRows
      columns={performanceColumns({ cohort: cohort ?? null })}
      currency="RUB"
    />
  )
}

const cohort = netPerformanceFixture.skus[0]?.points[0]?.period.cohort
if (cohort == null) throw new Error('Missing synthetic net cohort')
const html = (value: Parameters<typeof PerformanceNetForecast>[0]['cohort']) =>
  renderToStaticMarkup(<PerformanceNetForecast cohort={value} />)

describe('independent net quantity and revenue', () => {
  it.each([null, undefined])('does not invent a cohort for %s', (value) => {
    const result = html(value)
    expect(result).toContain('Ожидаемые нетто-продажи')
    expect(result).toContain('Ожидаемая нетто-выручка')
    expect(result.match(/>—</g)).toHaveLength(2)
    expect(result).toContain('Нет полных данных для нетто-прогноза')
    expect(result).not.toContain('0,000')
  })

  it('displays quantity when only the original price is missing', () => {
    const result = html({
      ...cohort,
      expectedNetRevenue: null,
      netForecastMoneyUnavailableReason: 'money_price_missing',
    })
    expect(result).toContain('>90,125<')
    expect(result).toContain('Нет цены исходной продажи')
    expect(result).not.toContain('Нетто-прогноз недоступен')
    expect(result).not.toContain('Продажи и выручка:')
    expect(result).toContain('SKU, кабинет')
  })

  it('displays server zero independently of unavailable quantity', () => {
    const result = html({
      ...cohort,
      expectedNetSalesQuantity: null,
      netForecastUnavailableReason: 'source_history_unavailable',
      expectedNetRevenue: { minor: '0', currency: 'KWD', fractionDigits: 3 },
    })
    expect(result).toContain('0,000')
    expect(result).toContain('KWD')
    expect(result).toContain('История наблюдений')
    const zeros = html({
      ...cohort,
      expectedNetSalesQuantity: '0.000',
      expectedNetRevenue: { minor: '0', currency: 'RUB', fractionDigits: 2 },
    })
    expect(zeros).toContain('>0,000<')
    expect(zeros).toContain('0,00')
  })

  it('uses exact money currency and signs, and explains invalid money', () => {
    expect(
      html({
        ...cohort,
        expectedNetRevenue: { minor: '-1', currency: 'CLF', fractionDigits: 4 },
      }),
    ).toContain('-0,0001')
    expect(
      html({
        ...cohort,
        expectedNetRevenue: {
          minor: '-9223372036854775808',
          currency: 'RUB',
          fractionDigits: 2,
        },
      }),
    ).toContain('-92 233 720 368 547 758,08')
    const invalid = html({
      ...cohort,
      expectedNetRevenue: { minor: '01', currency: 'RUB', fractionDigits: 2 },
    })
    expect(invalid).toContain('Нетто-выручка недоступна.')
    expect(invalid).toContain('>90,125<')
    expect(invalid).not.toContain('0,01')
  })

  it('explains actual quality reasons without fabricating training or samples', () => {
    const result = html({
      ...cohort,
      netForecastQuality: {
        sourceUnavailableReason: 'returns_coverage_missing',
        sourceMoneyUnavailableReason: 'money_currency_missing',
        trainingScopes: [],
        componentReasons: ['insufficient_sample', 'unexpected'],
        validationStatus: 'validation_pending',
        assessmentStatus: 'not_assessed',
      },
    })
    expect(result).toContain('возвратов')
    expect(result).toContain('Неизвестна валюта')
    expect(result).toContain('Недостаточно пригодных наблюдений')
    expect(result).toContain('Оценка по этим данным не выполнялась')
    expect(result).not.toContain('unexpected')
    expect(result).not.toContain('SKU, кабинет')
    expect(result).not.toContain('обучена')
    expect(result).not.toContain('точность подтверждена')
  })
})

it('associates one shared pending explanation with both unavailable values', () => {
  const result = html({
    ...cohort,
    expectedNetSalesQuantity: null,
    expectedNetRevenue: null,
    netForecastUnavailableReason: 'validation_pending',
    netForecastMoneyUnavailableReason: 'validation_pending',
    netForecastQuality: {
      sourceUnavailableReason: 'validation_pending',
      sourceMoneyUnavailableReason: 'validation_pending',
      trainingScopes: [],
      componentReasons: ['validation_pending', 'validation_pending'],
      validationStatus: 'validation_pending',
      assessmentStatus: 'not_assessed',
    },
  })
  expect(result.match(/>—</g)).toHaveLength(2)
  expect(result).toContain(
    'Продажи и выручка: Публикация предварительного прогноза пока не включена',
  )
  expect(
    result.match(/Публикация предварительного прогноза пока не включена/g),
  ).toHaveLength(1)
  expect(result.match(/Оценка по этим данным не выполнялась/g)).toHaveLength(1)
})

it('keeps different local reasons and distinct quality information while removing duplicate sources', () => {
  const result = html({
    ...cohort,
    expectedNetSalesQuantity: null,
    expectedNetRevenue: null,
    netForecastUnavailableReason: 'source_history_unavailable',
    netForecastMoneyUnavailableReason: 'money_price_missing',
    netForecastQuality: {
      sourceUnavailableReason: 'source_history_unavailable',
      sourceMoneyUnavailableReason: 'money_price_missing',
      trainingScopes: ['sku'],
      componentReasons: [
        'returns_coverage_missing',
        'insufficient_sample',
        'insufficient_sample',
      ],
      validationStatus: 'validation_pending',
      assessmentStatus: 'not_assessed',
    },
  })
  expect(result).not.toContain('Продажи и выручка:')
  expect(result).toMatch(/<p>Количество: История наблюдений/)
  expect(result).toMatch(/<p>Выручка: Нет цены/)
  expect(
    result.match(/История наблюдений для нетто-прогноза недоступна/g),
  ).toHaveLength(1)
  expect(result.match(/Нет цены исходной продажи/g)).toHaveLength(1)
  expect(result.match(/Нет полного покрытия загрузок возвратов/g)).toHaveLength(
    1,
  )
  expect(result.match(/Недостаточно пригодных наблюдений/g)).toHaveLength(1)
  expect(result).toContain(
    'Публикация предварительного прогноза пока не включена',
  )
  expect(result).toContain('Данные оценки: SKU')
})
