import type { SkuSalesSummaryResponse } from '../api/client'
import { formatMinorAmount } from '../shared/lib/formatMinorAmount'
import type { OverlayData } from '../shared/overlayRequest'

/**
 * Панель Conwix на карточке маркетплейса.
 *
 * Чужая поверхность — свои стили. Ни `packages/ui`, ни токенов Conwix
 * здесь нет намеренно (docs/patterns.md, «Расширение браузера»):
 * панель должна выглядеть частью карточки Ozon, а не нашлёпкой
 * из другого продукта. Popup — наша поверхность, там всё наоборот.
 *
 * Всё внутри Shadow DOM: стили хозяина не достают наши, наши не ломают
 * его вёрстку. Без этого пришлось бы либо тащить сюда Tailwind с его
 * preflight, который снёс бы карточку, либо писать селекторы с расчётом
 * на чужой CSS.
 */

const HOST_ID = 'conwix-overlay'

const STYLE = `
:host { all: initial; }
.panel {
  display: block;
  margin: 12px 0;
  padding: 12px 14px;
  border: 1px solid rgba(0, 0, 0, 0.12);
  border-radius: 12px;
  background: #fff;
  color: #001a34;
  font: 400 14px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
}
.title { font-size: 12px; letter-spacing: .02em; text-transform: uppercase; opacity: .55; margin-bottom: 8px; }
.row { display: flex; justify-content: space-between; gap: 16px; padding: 2px 0; }
.muted { opacity: .6; }
.value { font-variant-numeric: tabular-nums; }
/* Кнопка отслеживания. Своя, не из packages/ui: чужая поверхность —
   чужой дизайн (docs/patterns.md, «Расширение браузера»). */
.track {
  display: block;
  width: 100%;
  margin-top: 10px;
  padding: 8px 12px;
  border: 1px solid rgba(0, 0, 0, .16);
  border-radius: 8px;
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 13px;
  cursor: pointer;
}
.track:hover:not(:disabled) { background: rgba(0, 0, 0, .04); }
.track:disabled { opacity: .5; cursor: default; }
.error { margin-top: 6px; font-size: 12px; color: #b3261e; }
/* Тёмная тема Ozon: панель обязана её видеть, иначе на тёмной странице
   получится белый прямоугольник. Ориентируемся на системную тему —
   переключатель самого Ozon отсюда не виден, и это известный предел. */
@media (prefers-color-scheme: dark) {
  .panel { background: #1a1a1a; color: #f5f5f5; border-color: rgba(255, 255, 255, .14); }
  .track { border-color: rgba(255, 255, 255, .2); }
  .track:hover:not(:disabled) { background: rgba(255, 255, 255, .08); }
  .error { color: #f2b8b5; }
}
`

/**
 * `onToggleTracking` возвращает состояние, которое установилось на самом
 * деле, и текст ошибки, если сервер отказал. Панель не решает сама,
 * что произошло: включение может упереться в потолок артикулов или
 * в неактивное подключение, и показать «отслеживается» в этот момент
 * значило бы соврать.
 */
export function renderPanel(
  anchor: Element,
  data: OverlayData,
  onToggleTracking: (tracked: boolean) => Promise<TrackingOutcome>,
): void {
  const host = ensureHost(anchor)
  const shadow = host.shadowRoot
  if (null === shadow) {
    return
  }

  shadow.replaceChildren(styleElement(), panelElement(data, onToggleTracking))
}

export interface TrackingOutcome {
  readonly tracked: boolean
  readonly error: string | null
}

export function removePanel(): void {
  document.getElementById(HOST_ID)?.remove()
}

/**
 * Хост вставляется в поток вёрстки рядом с якорем, а не позиционируется
 * координатами: так панель раздвигает карточку сама и не нуждается
 * в пересчёте при скролле, ресайзе и раскрытии их аккордеонов.
 *
 * Плата — их React может снести узел при ре-рендере. Сторож,
 * возвращающий панель на место, живёт в content/overlay.ts.
 */
function ensureHost(anchor: Element): HTMLElement {
  const existing = document.getElementById(HOST_ID)
  if (null !== existing) {
    return existing
  }

  const host = document.createElement('div')
  host.id = HOST_ID
  host.attachShadow({ mode: 'open' })
  anchor.insertAdjacentElement('afterend', host)

  return host
}

function styleElement(): HTMLStyleElement {
  const style = document.createElement('style')
  style.textContent = STYLE

  return style
}

function panelElement(
  data: OverlayData,
  onToggleTracking: (tracked: boolean) => Promise<TrackingOutcome>,
): HTMLElement {
  const panel = document.createElement('div')
  panel.className = 'panel'
  panel.append(titleElement(data.sales.days))
  panel.append(...salesRows(data.sales))
  panel.append(...trackingControls(data.tracked, onToggleTracking))

  return panel
}

/**
 * Кнопка и место под ошибку. Пока запрос в пути, кнопка выключена:
 * второй клик по ней означал бы второй запрос на то же самое, а сервер
 * и так идемпотентен — просто незачем.
 */
function trackingControls(
  tracked: boolean,
  onToggleTracking: (tracked: boolean) => Promise<TrackingOutcome>,
): readonly HTMLElement[] {
  const button = document.createElement('button')
  button.type = 'button'
  button.className = 'track'

  const error = document.createElement('div')
  error.className = 'error'
  error.hidden = true

  let current = tracked
  const paint = (): void => {
    button.textContent = current ? 'Не отслеживать цену' : 'Отслеживать цену'
  }
  paint()

  button.addEventListener('click', () => {
    button.disabled = true
    error.hidden = true
    button.textContent = 'Сохраняем…'

    void onToggleTracking(!current).then((outcome) => {
      current = outcome.tracked
      paint()
      button.disabled = false
      if (null !== outcome.error) {
        error.textContent = outcome.error
        error.hidden = false
      }
    })
  })

  return [button, error]
}

function salesRows(summary: SkuSalesSummaryResponse): readonly HTMLElement[] {
  const total = summary.totals[0]
  if (undefined === total) {
    return [row('Продаж за период нет', '', true)]
  }

  const rows = [
    row(
      'Заказано',
      `${total.orderedQuantity} шт · ${formatMinorAmount(total.orderedAmountMinor, total.currency)}`,
    ),
    row(
      'Доставлено',
      `${total.deliveredQuantity} шт · ${formatMinorAmount(total.deliveredAmountMinor, total.currency)}`,
    ),
    row(
      'Отменено',
      `${total.cancelledQuantity} шт`,
      0 === total.cancelledQuantity,
    ),
  ]

  // Больше одной валюты — показываем это прямо, а не складываем
  // (CLAUDE.md §3). В реальности сегодня не встречается.
  if (summary.totals.length > 1) {
    rows.push(row('Есть продажи в других валютах', '', true))
  }

  return rows
}

function titleElement(days: number): HTMLElement {
  const title = document.createElement('div')
  title.className = 'title'
  title.textContent = `Conwix · ${days} дней`

  return title
}

function row(label: string, value: string, muted = false): HTMLElement {
  const element = document.createElement('div')
  element.className = muted ? 'row muted' : 'row'

  const labelNode = document.createElement('span')
  labelNode.textContent = label
  const valueNode = document.createElement('span')
  valueNode.className = 'value'
  valueNode.textContent = value

  element.append(labelNode, valueNode)

  return element
}
