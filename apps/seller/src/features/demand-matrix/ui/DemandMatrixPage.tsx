import { useEffect, useState } from 'react'
import { ChevronLeft, ChevronRight, CircleX, Grid3x3 } from 'lucide-react'
import { useNavigate, useParams, useSearchParams } from 'react-router'

import {
  Button,
  Card,
  Input,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { ApiError } from '../../../api/ApiError'
import {
  demandMatrixSearchWithCursor,
  demandMatrixSearchWithText,
  MAX_SEARCH_LENGTH,
  parseDemandMatrixSearch,
} from '../lib/demandMatrixParams'
import { useDemandMatrix } from '../model/useDemandMatrix'
import { DemandMatrixTable } from './DemandMatrixTable'

const PAGE_SIZE = 50
const QUANTITY = new Intl.NumberFormat('ru-RU')

interface CursorStack {
  key: string
  cursors: (string | null)[]
}

export function DemandMatrixPage() {
  const navigate = useNavigate()
  const { companyId } = useParams<{ companyId: string }>()
  const [params, setParams] = useSearchParams()
  const search = parseDemandMatrixSearch(params)
  const rawCursor = params.get('cursor')
  const cursor = rawCursor === '' ? null : rawCursor
  const viewKey = `${companyId ?? ''}:${search}`
  const [searchInput, setSearchInput] = useState(search)
  // Поле следует за адресом: переход по меню или ссылке с другим поиском
  // не перемонтирует страницу, и без этого поле показывало бы прежний
  // текст при запросе по новому.
  const [syncedSearch, setSyncedSearch] = useState(search)
  if (syncedSearch !== search) {
    setSyncedSearch(search)
    setSearchInput(search)
  }
  const [stack, setStack] = useState<CursorStack>({
    key: viewKey,
    cursors: [cursor],
  })
  const cursors =
    stack.key === viewKey && stack.cursors.at(-1) === cursor
      ? stack.cursors
      : [cursor]

  const query = useDemandMatrix(
    companyId ?? '',
    { search, limit: PAGE_SIZE, cursor },
    { enabled: companyId !== undefined },
  )

  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 403) {
      void navigate('/companies', { replace: true })
    }
  }, [query.error, navigate])

  if (companyId === undefined) {
    return (
      <Card tone="negative">
        <StatusPanel
          description="Откройте экран из списка компаний."
          icon={<CircleX aria-hidden="true" size={20} />}
          role="alert"
          title="Компания не выбрана"
          tone="negative"
        />
      </Card>
    )
  }

  const writeCursor = (value: string | null): void => {
    setParams(demandMatrixSearchWithCursor(params, value), { replace: true })
  }

  const nextCursor =
    query.status === 'success' ? (query.data.nextCursor ?? null) : null
  // Курсор привязан к дню расчёта и поиску: ссылка прошлых суток
  // получает 422 и предлагает начать с первой страницы.
  const staleCursor =
    query.error instanceof ApiError &&
    query.error.status === 422 &&
    cursor !== null

  return (
    <section className="flex flex-col gap-4">
      <header className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold">Спрос по кластерам</h1>
        <p className="max-w-3xl text-sm text-text-muted">
          Где покупают каждый товар: заказы по кластеру покупателя, а не по
          складу отгрузки, за последние полные дни без отменённых. Цвет ячейки —
          доля кластера в спросе товара. Значок коробки — в кластере были дни
          без товара: спрос там занижен, а пустая ячейка с «?» значит «спрос
          неизвестен», а не «спроса нет».
        </p>
      </header>

      <Card>
        <form
          className="flex flex-wrap items-end gap-2"
          onSubmit={(event) => {
            event.preventDefault()
            setParams(demandMatrixSearchWithText(params, searchInput), {
              replace: true,
            })
            setStack({ key: '', cursors: [null] })
          }}
        >
          <div className="min-w-60 flex-1">
            <Input
              label="Найти товар"
              maxLength={MAX_SEARCH_LENGTH}
              onChange={(event) => setSearchInput(event.target.value)}
              placeholder="SKU, артикул продавца или название"
              value={searchInput}
            />
          </div>
          <Button type="submit" variant="secondary">
            Найти
          </Button>
        </form>
      </Card>

      {query.status === 'pending' ? (
        <Card>
          <StatusPanel
            icon={<Grid3x3 aria-hidden="true" size={20} />}
            title="Считаем спрос…"
          />
        </Card>
      ) : null}

      {query.status === 'error' ? (
        <Card tone="negative">
          <StatusPanel
            action={
              staleCursor ? (
                <Button
                  onClick={() => {
                    setStack({ key: '', cursors: [null] })
                    writeCursor(null)
                  }}
                  size="compact"
                  type="button"
                  variant="secondary"
                >
                  С первой страницы
                </Button>
              ) : (
                <Button
                  onClick={() => {
                    void query.refetch()
                  }}
                  size="compact"
                  type="button"
                  variant="secondary"
                >
                  Повторить
                </Button>
              )
            }
            description={
              staleCursor
                ? 'Ссылка на страницу устарела: курсор живёт до следующих суток.'
                : query.error instanceof Error
                  ? query.error.message
                  : 'Попробуйте обновить страницу.'
            }
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Не удалось посчитать спрос"
            tone="negative"
          />
        </Card>
      ) : null}

      {query.status === 'success' ? (
        <>
          {/* Неполнота называется словами: недогруженный день выглядел бы
              падением тренда, а не пробелом в данных. */}
          {query.data.incompleteSalesAccounts > 0 ? (
            <Card tone="warning">
              <p className="text-sm">
                У {QUANTITY.format(query.data.incompleteSalesAccounts)} подключ.
                Ozon продажи за последние дни загружены не полностью — заказы и
                тренд могут быть занижены. Проверьте разделы «Подключения» и
                «Полнота данных».
              </p>
            </Card>
          ) : null}

          <div className="overflow-hidden rounded-xl border border-border-default bg-surface-raised shadow-card">
            <div className="flex flex-col gap-0.5 border-b border-border-default px-4 py-3">
              <span className="font-semibold">Товар × кластер покупателя</span>
              <span className="text-xs text-text-muted">
                В ячейке — спрос в день за {query.data.definitions.windowDays}{' '}
                дн и доля кластера. Тренд — спрос последних{' '}
                {query.data.definitions.shortDays} дн к{' '}
                {query.data.definitions.windowDays}, считается от{' '}
                {query.data.definitions.minSales} заказов. Окна заканчиваются{' '}
                {formatDate(query.data.definitions.windowEnd)}.
              </span>
            </div>
            {query.data.items.length === 0 && cursors.length <= 1 ? (
              <p className="px-4 py-6 text-sm text-text-muted">
                {search === ''
                  ? `Заказов за ${query.data.definitions.windowDays} дней нет.`
                  : 'Товаров с заказами по такому запросу нет.'}
              </p>
            ) : (
              <DemandMatrixTable
                clusters={query.data.clusters}
                items={query.data.items}
                minSales={query.data.definitions.minSales}
                windowDays={query.data.definitions.windowDays}
              />
            )}
            <div className="flex items-center justify-end gap-2 border-t border-border-default px-4 py-2">
              <Button
                disabled={cursors.length <= 1}
                onClick={() => {
                  const previous = cursors.slice(0, -1)
                  setStack({ key: viewKey, cursors: previous })
                  writeCursor(previous.at(-1) ?? null)
                }}
                size="compact"
                type="button"
                variant="secondary"
              >
                <ChevronLeft aria-hidden="true" size={16} />
                Назад
              </Button>
              <Button
                disabled={nextCursor === null}
                onClick={() => {
                  if (nextCursor !== null) {
                    setStack({
                      key: viewKey,
                      cursors: [...cursors, nextCursor],
                    })
                    writeCursor(nextCursor)
                  }
                }}
                size="compact"
                type="button"
                variant="secondary"
              >
                Дальше
                <ChevronRight aria-hidden="true" size={16} />
              </Button>
            </div>
          </div>
        </>
      ) : null}
    </section>
  )
}

function formatDate(date: string): string {
  const [year, month, day] = date.split('-')

  return `${day ?? ''}.${month ?? ''}.${year ?? ''}`
}
