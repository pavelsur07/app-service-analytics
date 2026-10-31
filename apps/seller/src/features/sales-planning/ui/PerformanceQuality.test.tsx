import { renderToStaticMarkup } from 'react-dom/server'
import { expect, it } from 'vitest'
import { PerformanceQuality } from './PerformanceQuality'

it.each([
  ['complete', 'Полностью', 'Заказы загружены полностью'],
  ['missing', 'Нет данных', 'Заказы не загружены'],
  ['partial', 'Частично', 'Заказы загружены частично'],
])(
  'keeps %s coverage readable while hiding the full explanation',
  (coverage, short, full) => {
    const html = renderToStaticMarkup(
      <PerformanceQuality coverage={coverage} current={false} />,
    )
    expect(html).toContain(`>${short}</span>`)
    expect(html).toContain('aria-label="Пояснение: полнота данных о заказах"')
    expect(html).toContain('aria-expanded="false"')
    expect(html.split('hidden=""')[1]).toContain(full)
  },
)

it.each([
  [true, false, 'Текущий день ещё не завершён'],
  [false, true, 'Будущие даты'],
])(
  'keeps time context available in collapsed details',
  (current, future, text) => {
    const html = renderToStaticMarkup(
      <PerformanceQuality
        coverage="complete"
        current={current}
        future={future}
      />,
    )
    expect(html).toContain('aria-expanded="false"')
    expect(html).toContain(text)
  },
)
