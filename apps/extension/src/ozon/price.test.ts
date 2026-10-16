import { describe, expect, it } from 'vitest'

import markup from './__fixtures__/product-markup-308403988.json'
import webPriceState from './__fixtures__/web-price-state-308520421.json'
import { readCardPrice, readDisplayedPrice } from './price'

/**
 * Фикстура — настоящая разметка карточки, снятая спайком ADR-015
 * 2026-08-18. Цена в ней 1117 ₽ при цене кабинета 2537 ₽: это и есть
 * то, что видит покупатель после соинвеста Ozon, а разница между ними —
 * СПП.
 *
 * DOM подделан десятью строками, а не поднят jsdom: разбор трогает
 * ровно два вызова — `querySelectorAll` и `textContent`, — и ради них
 * заводить внешнюю зависимость не нужно (её установка к тому же
 * требует согласования, CLAUDE.md). Плата названа честно: опечатку
 * в самом селекторе такой тест не поймает, её поймает ручная проверка
 * из чек-листа публикации.
 */
const SKU = '308403988'

interface FakeScript {
  readonly type: string
  readonly text: string
}

function pageWith(...scripts: readonly FakeScript[]): ParentNode {
  const nodes = scripts.map((script) => ({ textContent: script.text }))

  return {
    querySelectorAll: (selector: string) =>
      selector.includes('ld+json')
        ? scripts
            .map((script, i) => ({ script, node: nodes[i] }))
            .filter(({ script }) => 'application/ld+json' === script.type)
            .map(({ node }) => node)
        : nodes,
  } as unknown as ParentNode
}

function ldJson(value: unknown): FakeScript {
  return { type: 'application/ld+json', text: JSON.stringify(value) }
}

describe('витринная цена из разметки карточки', () => {
  it('читает цену и валюту с настоящей карточки', () => {
    expect(readDisplayedPrice(pageWith(ldJson(markup)), SKU)).toEqual({
      ok: true,
      price: { amountMinor: 111_700, currency: 'RUB' },
    })
  })

  it('находит разметку и без объявленного типа', () => {
    // Тип вставленного скрипта — деталь сборки Nuxt, не контракт.
    const untyped = { type: 'application/json', text: JSON.stringify(markup) }

    expect(readDisplayedPrice(pageWith(untyped), SKU)).toMatchObject({
      ok: true,
    })
  })

  it('достаёт товар из @graph', () => {
    const graph = ldJson({
      '@context': 'http://schema.org',
      '@graph': [{ '@type': 'WebPage' }, markup],
    })

    expect(readDisplayedPrice(pageWith(graph), SKU)).toMatchObject({ ok: true })
  })

  it('отказывается писать цену чужой карточки', () => {
    // Редирект увёл на другой товар: записать его цену под нашим
    // артикулом означало бы завести правдоподобную ложь.
    expect(readDisplayedPrice(pageWith(ldJson(markup)), '999999999')).toEqual({
      ok: false,
      reason: 'sku-mismatch',
    })
  })

  it('разбирает копейки целочисленно', () => {
    const withKopecks = ldJson({
      ...markup,
      offers: { ...markup.offers, price: '1117.05' },
    })

    expect(readDisplayedPrice(pageWith(withKopecks), SKU)).toEqual({
      ok: true,
      price: { amountMinor: 111_705, currency: 'RUB' },
    })
  })

  it('не выдумывает валюту', () => {
    // Подставить RUB значило бы решить за площадку (ADR-004).
    const withoutCurrency = ldJson({
      ...markup,
      offers: { ...markup.offers, priceCurrency: '' },
    })

    expect(readDisplayedPrice(pageWith(withoutCurrency), SKU)).toEqual({
      ok: false,
      reason: 'price-unreadable',
    })
  })

  it('сообщает, когда разметки нет вовсе', () => {
    // Ozon перестал публиковать schema.org — это поломка на нашей
    // стороне, и она обязана быть видимой, а не выглядеть как «цены нет».
    expect(readDisplayedPrice(pageWith(), SKU)).toEqual({
      ok: false,
      reason: 'markup-missing',
    })
  })

  it('переживает скрипт с испорченным JSON', () => {
    const broken = { type: 'application/ld+json', text: '{"@type": не json' }

    expect(
      readDisplayedPrice(pageWith(broken, ldJson(markup)), SKU),
    ).toMatchObject({ ok: true })
  })
})

/**
 * Фикстура — состояние блока цены живой карточки 308520421, снятое
 * владельцем 2026-10-16 из консоли (покупатель вошёл в аккаунт):
 * `cardPrice` «1 413 ₽» — с Ozon Картой, `price` «1 570 ₽» — без неё,
 * совпадает с `offers.price` разметки той же карточки. Пробелы в суммах
 * при копировании через консоль могли стать обычными; разбор принимает
 * любые, и случай с неразрывными проверен отдельно.
 */
describe('цена с Ozon Картой из состояния блока цены', () => {
  const WITHOUT_CARD = { amountMinor: 157_000, currency: 'RUB' }

  function pageWithState(state: string | null): ParentNode {
    return {
      querySelector: (selector: string) =>
        null !== state && selector.includes('state-webPrice')
          ? {
              getAttribute: (name: string) =>
                'data-state' === name ? state : null,
            }
          : null,
    } as unknown as ParentNode
  }

  function stateWith(fields: Record<string, unknown>): string {
    return JSON.stringify({ ...webPriceState, ...fields })
  }

  it('читает цену с картой с настоящей карточки', () => {
    expect(
      readCardPrice(pageWithState(JSON.stringify(webPriceState)), WITHOUT_CARD),
    ).toEqual({ ok: true, price: { amountMinor: 141_300, currency: 'RUB' } })
  })

  it('принимает неразрывные и узкие пробелы между разрядами', () => {
    const state = stateWith({
      cardPrice: '1\u00a0413\u2009₽',
      price: '1\u202f570 ₽',
    })

    expect(readCardPrice(pageWithState(state), WITHOUT_CARD)).toEqual({
      ok: true,
      price: { amountMinor: 141_300, currency: 'RUB' },
    })
  })

  it('без блока состояния или без поля цены с картой нет — это не поломка', () => {
    expect(readCardPrice(pageWithState(null), WITHOUT_CARD)).toEqual({
      ok: true,
      price: null,
    })
    expect(
      readCardPrice(
        pageWithState(stateWith({ cardPrice: undefined })),
        WITHOUT_CARD,
      ),
    ).toEqual({ ok: true, price: null })
  })

  it('не берёт цену с картой из блока другого товара', () => {
    // Цена без карты в состоянии не совпала с разметкой карточки:
    // состояние чужое, и его цена с картой легла бы под наш артикул.
    expect(
      readCardPrice(pageWithState(JSON.stringify(webPriceState)), {
        amountMinor: 111_700,
        currency: 'RUB',
      }),
    ).toEqual({ ok: false, reason: 'state-price-mismatch' })
  })

  it('не принимает число без знака рубля и испорченное состояние', () => {
    expect(
      readCardPrice(
        pageWithState(stateWith({ cardPrice: '1413' })),
        WITHOUT_CARD,
      ),
    ).toEqual({ ok: false, reason: 'card-price-unreadable' })
    expect(readCardPrice(pageWithState('{не json'), WITHOUT_CARD)).toEqual({
      ok: false,
      reason: 'state-unreadable',
    })
  })
})
