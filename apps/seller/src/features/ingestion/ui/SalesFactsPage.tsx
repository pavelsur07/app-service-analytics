import { useEffect, useState } from 'react'
import { ChevronLeft, ChevronRight, CircleX, LoaderCircle } from 'lucide-react'
import { useNavigate, useParams } from 'react-router'
import { ApiError } from '../../../api/ApiError'
import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import { useSalesFacts } from '../model/useSalesFacts'
import { SalesFactsTable, SalesFactsTableSkeleton } from './SalesFactsTable'

// Без переключения компании в интерфейсе (список — features/auth) —
// companyId только из URL, источник правды (docs/patterns.md).
export function SalesFactsPage() {
  const navigate = useNavigate()
  const { companyId } = useParams<{ companyId: string }>()
  const [cursorStack, setCursorStack] = useState<(string | null)[]>([null])
  const cursor = cursorStack[cursorStack.length - 1] ?? null

  const query = useSalesFacts(companyId ?? '', cursor, {
    enabled: companyId !== undefined,
  })

  // 403 — не "тихий пустой экран" (ТЗ §6): companyId в URL не значит
  // доступ, отказ уводит на список компаний, доступных этому пользователю.
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
            action={
              <Button
                type="button"
                variant="secondary"
                size="compact"
                onClick={() => {
                  void navigate('/companies')
                }}
              >
                К компаниям
              </Button>
            }
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

  const nextCursor = query.data?.nextCursor ?? null

  return (
    <div>
      <div className="flex flex-col gap-4">
        <h1 className="text-xl font-semibold">Продажи Ozon</h1>

        {query.status === 'pending' && <SalesFactsTableSkeleton />}

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
              title="Не удалось загрузить продажи"
              tone="negative"
            />
          </Card>
        )}

        {/* Нуль неотличим от посчитанного нуля: сразу после подключения
            кабинета фактов ещё нет, и SalesFactsTable на пустом списке
            говорит «нет данных за период» — верно после реальной
            синхронизации, но не в первые минуты после подключения,
            когда данных ещё не завезли. Эта ветка идёт раньше таблицы
            и подменяет её ровно тогда, когда список пуст. */}
        {query.status === 'success' && query.data.items.length === 0 && (
          <Card>
            <StatusPanel
              icon={
                <LoaderCircle
                  aria-hidden="true"
                  className="animate-spin"
                  size={20}
                />
              }
              title="Данные загружаются"
              description="Мы забираем историю за текущий месяц. Экран наполнится сам — обновлять страницу не нужно."
              tone="accent"
            />
          </Card>
        )}

        {query.status === 'success' && query.data.items.length > 0 && (
          <>
            <SalesFactsTable items={query.data.items} />
            <div className="flex items-center justify-end gap-2">
              <Button
                type="button"
                variant="secondary"
                size="compact"
                disabled={cursorStack.length <= 1}
                onClick={() => {
                  setCursorStack((stack) =>
                    stack.length > 1 ? stack.slice(0, -1) : stack,
                  )
                }}
              >
                <ChevronLeft aria-hidden="true" size={16} />
                Назад
              </Button>
              <Button
                type="button"
                variant="secondary"
                size="compact"
                disabled={nextCursor === null}
                onClick={() => {
                  if (nextCursor !== null) {
                    setCursorStack((stack) => [...stack, nextCursor])
                  }
                }}
              >
                Дальше
                <ChevronRight aria-hidden="true" size={16} />
              </Button>
            </div>
          </>
        )}
      </div>
    </div>
  )
}
