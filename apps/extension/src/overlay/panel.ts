import type { SkuSalesSummaryResponse } from '../api/client'

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
/* Тёмная тема Ozon: панель обязана её видеть, иначе на тёмной странице
   получится белый прямоугольник. Ориентируемся на системную тему —
   переключатель самого Ozon отсюда не виден, и это известный предел. */
@media (prefers-color-scheme: dark) {
  .panel { background: #1a1a1a; color: #f5f5f5; border-color: rgba(255, 255, 255, .14); }
}
`

export function renderPanel(
  anchor: Element,
  summary: SkuSalesSummaryResponse,
): void {
  const host = ensureHost(anchor)
  const shadow = host.shadowRoot
  if (null === shadow) {
    return
  }

  shadow.replaceChildren(styleElement(), panelElement(summary))
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

function panelElement(summary: SkuSalesSummaryResponse): HTMLElement {
  const panel = document.createElement('div')
  panel.className = 'panel'
  panel.append(titleElement(summary.days))

  const total = summary.totals[0]
  if (undefined === total) {
    panel.append(row('Продаж за период нет', '', true))

    return panel
  }

  panel.append(
    row(
      'Заказано',
      `${total.orderedQuantity} шт · ${money(total.orderedAmountMinor, total.currency)}`,
    ),
    row(
      'Доставлено',
      `${total.deliveredQuantity} шт · ${money(total.deliveredAmountMinor, total.currency)}`,
    ),
    row(
      'Отменено',
      `${total.cancelledQuantity} шт`,
      0 === total.cancelledQuantity,
    ),
  )

  // Больше одной валюты — показываем это прямо, а не складываем
  // (CLAUDE.md §3). В реальности сегодня не встречается.
  if (summary.totals.length > 1) {
    panel.append(row('Есть продажи в других валютах', '', true))
  }

  return panel
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

/**
 * Минорные единицы → строка. Деление на 100 здесь — форматирование
 * для показа, а не арифметика над деньгами: складывать и вычитать
 * величины расширение не имеет права, это делает бэкенд (CLAUDE.md §3).
 */
function money(amountMinor: number, currency: string): string {
  const formatted = new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency,
    maximumFractionDigits: 0,
  }).format(amountMinor / 100)

  return formatted
}
