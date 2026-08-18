import { useEffect, useState } from 'react'
import { CircleX, Package, Pencil, Plus } from 'lucide-react'
import { useNavigate, useParams } from 'react-router'
import { ApiError } from '../../../api/ApiError'
import type { components } from '../../../api/schema'
import {
  Badge,
  Button,
  Card,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import {
  correctionImpact,
  listingsWithoutCost,
  parseAmountToMinor,
} from '../lib/cost'
import {
  useCorrectListingCost,
  useListingCosts,
  useSetListingCost,
} from '../model/useListingCosts'

type ListingCostItem = components['schemas']['ListingCostItemResponse']

/** Форма открыта либо на новую цену, либо на исправление — не на оба. */
type OpenForm =
  { kind: 'set'; key: string } | { kind: 'correct'; key: string } | null

/**
 * Ключ карточки — пара «подключение + артикул площадки», как и ключ
 * себестоимости (ADR-013). Один только sku не годится: при второй
 * площадке он повторится, и форма открылась бы сразу под всеми
 * карточками с этим артикулом, а React потерял бы соответствие строк.
 */
function listingKey(item: ListingCostItem): string {
  return `${item.marketplaceAccountId}:${item.marketplaceSku}`
}

const CURRENCY = 'RUB'

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

/**
 * ADR-013 требует назвать числом, что затронет исправление: сколько дней
 * и сколько проданных штук. «12 дней и 47 штук» — совсем другое решение,
 * чем «2 дня и 1 штука», и без этих чисел предупреждение остаётся
 * вежливой формальностью.
 */
function correctionWarning(item: ListingCostItem, serverToday: string): string {
  // Дата берётся из ответа, а не из часов браузера: штуки сервер
  // посчитал по календарю площадки, и локальная полночь восточнее
  // Москвы дала бы дни и штуки за разные периоды.
  const impact = correctionImpact(
    item.costEffectiveFrom ?? '',
    item.deliveredSinceCost ?? 0,
    serverToday,
  )

  return (
    `Исправление изменит уже показанную прибыль: цена действует ` +
    `с ${item.costEffectiveFrom ?? ''} — это ${impact.days} дн. ` +
    `и ${impact.units} проданных шт.`
  )
}

/**
 * Ввод себестоимости (ADR-013).
 *
 * Список отсортирован по выручке за месяц, и это главное решение экрана:
 * у клиента шестьдесят карточек, и он введёт цену у пяти-десяти — тех,
 * что кормят. Список по алфавиту заставлял бы искать их глазами.
 *
 * Два раздельных действия, а не одна кнопка «изменить»: новая цена
 * с даты прошлое не трогает, исправление — переписывает уже показанную
 * прибыль. Одна кнопка на оба случая означала бы, что ввод сегодняшней
 * закупки молча меняет отчёт за прошлый месяц.
 */
export function ListingCostsPage() {
  const navigate = useNavigate()
  const { companyId } = useParams<{ companyId: string }>()
  const [cursorStack, setCursorStack] = useState<(string | null)[]>([null])
  const [form, setForm] = useState<OpenForm>(null)
  const [amount, setAmount] = useState('')
  const [effectiveFrom, setEffectiveFrom] = useState(today())
  const cursor = cursorStack[cursorStack.length - 1] ?? null

  const query = useListingCosts(companyId ?? '', cursor, {
    enabled: companyId !== undefined,
  })
  const setCost = useSetListingCost(companyId ?? '')
  const correctCost = useCorrectListingCost(companyId ?? '')

  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 403) {
      void navigate('/companies', { replace: true })
    }
  }, [query.error, navigate])

  if (companyId === undefined) {
    return (
      <div>
        <Card tone="negative">
          <StatusPanel
            description="В адресе не указан companyId."
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Некорректный адрес"
            tone="negative"
          />
        </Card>
      </div>
    )
  }

  const closeForm = () => {
    setForm(null)
    setAmount('')
    setEffectiveFrom(today())
  }

  const submit = (item: ListingCostItem) => {
    const minor = parseAmountToMinor(amount)
    if (minor === null) {
      return
    }

    if (form?.kind === 'correct') {
      // Схема допускает и null, и отсутствие поля — сводим к одному
      // значению. Без цены и версии исправлять нечего: это карточка,
      // у которой цены ещё нет.
      const costId = item.costId ?? null
      const version = item.costVersion ?? null
      if (costId === null || version === null) {
        return
      }

      correctCost.mutate(
        {
          costId,
          unitCostMinor: minor,
          currency: item.costCurrency ?? CURRENCY,
          version,
        },
        { onSuccess: closeForm },
      )

      return
    }

    setCost.mutate(
      {
        marketplaceAccountId: item.marketplaceAccountId,
        marketplaceSku: item.marketplaceSku,
        effectiveFrom,
        unitCostMinor: minor,
        currency: CURRENCY,
      },
      { onSuccess: closeForm },
    )
  }

  const pending = setCost.isPending || correctCost.isPending
  const failure = setCost.error ?? correctCost.error

  return (
    <div>
      <div className="flex flex-col gap-4">
        <h1 className="text-xl font-semibold">Себестоимость</h1>

        {/* Прямо на экране: закупочная цена, а не «все затраты».
            Комиссию и логистику мы уже берём у Ozon, и второй раз они
            дали бы задвоенный расход (ADR-012). */}
        <p className="text-sm text-text-muted">
          Закупочная цена единицы товара и доставка до склада площадки — без
          комиссий и логистики самого Ozon: их мы уже учитываем отдельно. Список
          отсортирован по выручке за месяц: сверху то, ради чего стоит начинать.
        </p>

        {query.status === 'pending' && (
          <Card>
            <div className="h-32 animate-pulse rounded bg-border-subtle" />
          </Card>
        )}

        {query.status === 'error' && (
          <Card tone="negative">
            <StatusPanel
              action={
                <Button
                  type="button"
                  variant="secondary"
                  size="compact"
                  onClick={() => {
                    void query.refetch()
                  }}
                >
                  Повторить
                </Button>
              }
              description={
                query.error instanceof Error
                  ? query.error.message
                  : 'Неизвестная ошибка'
              }
              icon={<CircleX aria-hidden="true" size={20} />}
              role="alert"
              title="Не удалось загрузить список"
              tone="negative"
            />
          </Card>
        )}

        {query.status === 'success' && (
          <>
            <Card>
              <p className="text-sm">
                Себестоимость задана у {query.data.pricedCount} товаров из{' '}
                {query.data.listingCount}.{' '}
                {listingsWithoutCost(
                  query.data.listingCount,
                  query.data.pricedCount,
                ) > 0
                  ? 'Пока цена не задана, прибыль по товару не считается — экономика показывает только расходы площадки.'
                  : 'Все карточки с ценой.'}
              </p>
            </Card>

            {failure !== null && (
              <Card tone="negative">
                <StatusPanel
                  description={
                    failure instanceof ApiError
                      ? failure.message
                      : 'Не удалось сохранить цену.'
                  }
                  icon={<CircleX aria-hidden="true" size={20} />}
                  role="alert"
                  title="Цена не сохранена"
                  tone="negative"
                />
              </Card>
            )}

            {query.data.items.length === 0 && (
              <Card>
                <StatusPanel
                  description="Каталог ещё не загружен — карточки появятся после первой синхронизации."
                  icon={<Package aria-hidden="true" size={20} />}
                  title="Товаров пока нет"
                  tone="neutral"
                />
              </Card>
            )}

            {query.data.items.map((item) => (
              <Card key={listingKey(item)}>
                <div className="flex flex-col gap-2">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                      {/* Наименование первым, артикул продавца вторым:
                          селлер узнаёт товар по ним, а не по sku
                          площадки. */}
                      <div className="font-medium">
                        {item.name ?? item.marketplaceSku}
                      </div>
                      <div className="text-sm text-text-muted">
                        {item.offerId ?? item.marketplaceSku}
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      {(item.unitCostMinor ?? null) === null ? (
                        <Badge tone="warning">Цена не задана</Badge>
                      ) : (
                        <Badge tone="neutral">
                          {formatMinorAmount(
                            item.unitCostMinor ?? 0,
                            item.costCurrency ?? CURRENCY,
                          )}{' '}
                          с {item.costEffectiveFrom}
                        </Badge>
                      )}
                    </div>
                  </div>

                  <dl className="flex flex-wrap gap-x-8 gap-y-1 text-sm">
                    <div>
                      <dt className="text-text-muted">Выручка за месяц</dt>
                      <dd>{formatMinorAmount(item.revenueMinor, CURRENCY)}</dd>
                    </div>
                    <div>
                      <dt className="text-text-muted">Доставлено</dt>
                      <dd>{item.deliveredQuantity} шт</dd>
                    </div>
                  </dl>

                  <div className="flex flex-wrap gap-2">
                    <Button
                      type="button"
                      variant="secondary"
                      size="compact"
                      onClick={() => {
                        setForm({ kind: 'set', key: listingKey(item) })
                        setAmount('')
                        setEffectiveFrom(today())
                      }}
                    >
                      <Plus aria-hidden="true" size={14} />
                      Новая цена с даты
                    </Button>
                    {(item.costId ?? null) !== null && (
                      <Button
                        type="button"
                        variant="ghost"
                        size="compact"
                        onClick={() => {
                          setForm({ kind: 'correct', key: listingKey(item) })
                          setAmount('')
                        }}
                      >
                        <Pencil aria-hidden="true" size={14} />
                        Исправить действующую
                      </Button>
                    )}
                  </div>

                  {form?.key === listingKey(item) && (
                    <form
                      className="flex flex-col gap-2 border-t border-border-subtle pt-2"
                      onSubmit={(event) => {
                        event.preventDefault()
                        submit(item)
                      }}
                    >
                      {/* Разные тексты, потому что разные последствия.
                          Это единственное место, где экран обязан
                          сказать вслух, что прошлое изменится. */}
                      <p className="text-sm text-text-muted">
                        {form.kind === 'correct'
                          ? correctionWarning(item, query.data.to)
                          : 'Новая цена подействует с указанной даты. Отчёты за более ранние дни не изменятся.'}
                      </p>

                      <div className="flex flex-wrap items-end gap-2">
                        <label className="flex flex-col gap-1 text-sm">
                          Цена за штуку, ₽
                          <input
                            className="h-9 w-40 rounded-md border border-border-default bg-surface-raised px-2"
                            inputMode="decimal"
                            value={amount}
                            onChange={(event) => {
                              setAmount(event.target.value)
                            }}
                            placeholder="420,00"
                          />
                        </label>

                        {form.kind === 'set' && (
                          <label className="flex flex-col gap-1 text-sm">
                            Действует с
                            <input
                              className="h-9 rounded-md border border-border-default bg-surface-raised px-2"
                              type="date"
                              value={effectiveFrom}
                              onChange={(event) => {
                                setEffectiveFrom(event.target.value)
                              }}
                            />
                          </label>
                        )}

                        <Button
                          type="submit"
                          size="compact"
                          disabled={
                            pending || parseAmountToMinor(amount) === null
                          }
                        >
                          Сохранить
                        </Button>
                        <Button
                          type="button"
                          variant="ghost"
                          size="compact"
                          onClick={closeForm}
                        >
                          Отмена
                        </Button>
                      </div>
                    </form>
                  )}
                </div>
              </Card>
            ))}

            {((query.data.nextCursor ?? null) !== null ||
              cursorStack.length > 1) && (
              <div className="flex items-center justify-end gap-2">
                <Button
                  type="button"
                  variant="secondary"
                  size="compact"
                  disabled={cursorStack.length <= 1}
                  onClick={() => {
                    setCursorStack((stack: (string | null)[]) =>
                      stack.length > 1 ? stack.slice(0, -1) : stack,
                    )
                  }}
                >
                  Назад
                </Button>
                <Button
                  type="button"
                  variant="secondary"
                  size="compact"
                  disabled={(query.data.nextCursor ?? null) === null}
                  onClick={() => {
                    const next = query.data.nextCursor ?? null
                    if (next !== null) {
                      setCursorStack((stack: (string | null)[]) => [
                        ...stack,
                        next,
                      ])
                    }
                  }}
                >
                  Дальше
                </Button>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}
