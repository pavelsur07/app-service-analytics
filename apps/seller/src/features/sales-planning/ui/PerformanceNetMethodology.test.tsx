import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import { performanceFixture } from '../../../../tests/fixtures/salesPerformance'
import { PerformanceNetMethodology } from './PerformanceNetMethodology'

const metadata = performanceFixture.netForecast

describe('honest net methodology context', () => {
  it('separates pending publication from an assessment that never ran', () => {
    const html = renderToStaticMarkup(
      <PerformanceNetMethodology metadata={metadata} />,
    )
    expect(html).toContain('<details')
    expect(html).toContain('<summary')
    expect(html).toContain('Проверка качества прогноза ещё не завершена')
    expect(html).toContain('Оценка по этим данным не выполнялась')
    expect(html).toContain('Наблюдаемые продажи уже входят')
    expect(html).toContain('Будущие заказы не учитываются')
    expect(html).not.toContain('модель обучена')
    expect(html).not.toContain('epoch')
    expect(html).not.toContain('2026-07-03')
  })

  it.each([45, 90])('uses server horizon %s from delivery', (horizonDays) => {
    const html = renderToStaticMarkup(
      <PerformanceNetMethodology metadata={{ ...metadata, horizonDays }} />,
    )
    expect(html).toContain(`${horizonDays} дней от доставки`)
    expect(html).not.toContain('60 дней')
  })

  it.each([null, undefined])(
    'does not borrow legacy context for %s metadata',
    (value) => {
      const html = renderToStaticMarkup(
        <PerformanceNetMethodology metadata={value} />,
      )
      expect(html).toContain('Описание методики нетто-прогноза недоступно')
      expect(html).not.toContain('60 дней')
    },
  )

  it('does not describe an unknown methodology as the familiar model', () => {
    const html = renderToStaticMarkup(
      <PerformanceNetMethodology
        metadata={{ ...metadata, methodology: 'future_method' }}
      />,
    )
    expect(html).toContain('Описание методики нетто-прогноза недоступно')
    expect(html).not.toContain('Наблюдаемые продажи уже входят')
    expect(html).not.toContain('future_method')
  })
})
