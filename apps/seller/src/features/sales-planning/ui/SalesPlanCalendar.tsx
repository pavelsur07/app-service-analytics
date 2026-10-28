import { useDeferredValue, useId, useState } from 'react'
import { Button, Card, Input } from '../../../../../../packages/ui/src'
import type { components } from '../../../api/schema'
import { useCurrentUser } from '../../../shared/model/useCurrentUser'
import { statusLabel } from '../lib/salesPlan'
import {
  calendarEntryProblem,
  type CalendarEntry,
  type CalendarSnapshot,
} from '../lib/calendarDraft'
import { useSalesPlanCalendarEditor } from '../model/useSalesPlanCalendarEditor'
import { useCatalogSkus } from '../model/useSalesPlans'
import { CalendarEventsTable, calendarKindLabels } from './CalendarEventsTable'

type Plan = components['schemas']['SalesPlanResponse']

export function SalesPlanCalendar(props: { companyId: string; plan: Plan }) {
  const user = useCurrentUser()
  return (
    <CalendarScreen
      key={`${user.data?.email ?? ''}:${props.companyId}:${props.plan.id}`}
      {...props}
    />
  )
}

function CalendarScreen({
  companyId,
  plan,
}: {
  companyId: string
  plan: Plan
}) {
  const editor = useSalesPlanCalendarEditor(companyId, plan.id, plan.version)
  const { query, snapshot, entries, editing, mutation } = editor
  return (
    <Card>
      <section aria-label="Календарь плана" className="flex flex-col gap-3">
        <h2 className="text-lg font-semibold">Календарь плана</h2>
        <p className="text-sm text-text-muted">
          Продажи и подсорт могут совпадать по дате. Подсорт — плановое
          поступление на площадку. Стоп подсорт не останавливает продажи и уже
          отправленные партии.
        </p>
        {query.isPending && <p role="status">Загружаем календарь целиком…</p>}
        {query.isFetching && !query.isPending && (
          <p role="status">Перечитываем календарь…</p>
        )}
        {query.isError && (
          <div role="alert" className="text-sm text-negative-text">
            <p>
              Не удалось загрузить календарь целиком. Сохранение недоступно.
            </p>
            <p>{query.error.message}</p>
          </div>
        )}
        <div>
          <Button
            type="button"
            variant="secondary"
            size="compact"
            disabled={query.isFetching || mutation.isPending}
            onClick={() => {
              void query.refetch()
            }}
          >
            Перечитать календарь
          </Button>
        </div>
        {snapshot !== undefined && (
          <>
            <p className="text-sm text-text-secondary">
              {statusLabel(query.data?.status ?? snapshot.status)}. Версия
              календаря: {snapshot.version}.
              {!editor.editable && ' Только просмотр.'}
            </p>
            <dl className="flex flex-wrap gap-6 text-sm">
              <div>
                <dt className="text-text-muted">Начало сезона</dt>
                <dd>
                  <time dateTime={snapshot.seasonStart.date}>
                    {snapshot.seasonStart.date}
                  </time>
                </dd>
              </div>
              <div>
                <dt className="text-text-muted">Конец сезона</dt>
                <dd>
                  <time dateTime={snapshot.seasonEnd.date}>
                    {snapshot.seasonEnd.date}
                  </time>
                </dd>
              </div>
            </dl>
            <p className="text-xs text-text-muted">
              Границы вычисляются из периода плана и не редактируются.
            </p>
            {entries.length === 0 ? (
              <p className="text-sm text-text-muted">
                Ручных событий пока нет.
              </p>
            ) : (
              <CalendarEventsTable
                entries={entries}
                {...(editor.editable
                  ? {
                      actions: {
                        disabled: editing !== null || !editor.canChangeEntries,
                        edit: editor.startEditing,
                        remove: editor.remove,
                      },
                    }
                  : {})}
              />
            )}
            {editor.editable && (
              <div>
                <Button
                  type="button"
                  variant="secondary"
                  size="compact"
                  disabled={
                    editing !== null ||
                    entries.length >= 500 ||
                    !editor.canChangeEntries
                  }
                  onClick={() => {
                    editor.startEditing(null)
                  }}
                >
                  Добавить событие
                </Button>
              </div>
            )}
            {entries.length >= 500 && (
              <p className="text-sm text-text-muted">
                Достигнут предел: 500 событий. Удалите запись перед добавлением.
              </p>
            )}
            {editing !== null && (
              <CalendarEntryForm
                key={editing.index ?? 'new'}
                companyId={companyId}
                plan={plan}
                season={snapshot}
                initial={editing.entry}
                index={editing.index}
                disabled={!editor.editable || mutation.isPending}
                onApply={editor.apply}
                onCancel={editor.cancelEntry}
              />
            )}
            {editor.dirty && (
              <>
                <p role="status" className="text-sm text-text-secondary">
                  Есть локальные правки календаря. Версия для сохранения:{' '}
                  {snapshot.version}.
                </p>
                {(editor.conflict || editor.changed) && (
                  <p role="alert" className="text-sm text-warning-text">
                    План изменился. Ваш ввод сохранён. Перечитайте календарь и
                    сверьте правки. Чтобы принять серверные данные, нажмите
                    «Отменить локальные правки».
                  </p>
                )}
                {(editor.conflict || editor.changed) && query.isSuccess && (
                  <details className="text-sm">
                    <summary className="cursor-pointer rounded focus-visible:outline-2 focus-visible:outline-accent-default">
                      Серверный календарь после перечитывания
                    </summary>
                    <p className="mt-2">
                      Версия сервера: {query.data.version}.{' '}
                      {statusLabel(query.data.status)}.
                    </p>
                    {query.data.entries.length === 0 ? (
                      <p>На сервере ручных событий нет.</p>
                    ) : (
                      <CalendarEventsTable
                        label="События сервера"
                        entries={query.data.entries}
                      />
                    )}
                  </details>
                )}
                <div className="flex flex-wrap gap-2">
                  {editor.editable && (
                    <Button
                      type="button"
                      size="compact"
                      disabled={!editor.canSave}
                      loading={mutation.isPending}
                      onClick={editor.save}
                    >
                      Сохранить календарь
                    </Button>
                  )}
                  <Button
                    type="button"
                    size="compact"
                    variant="secondary"
                    disabled={
                      !query.isSuccess || query.isFetching || mutation.isPending
                    }
                    onClick={editor.cancelLocal}
                  >
                    Отменить локальные правки
                  </Button>
                </div>
              </>
            )}
          </>
        )}
        {mutation.isError && (
          <p role="alert" className="text-sm text-negative-text">
            {mutation.error.message} Локальные правки сохранены.
          </p>
        )}
        {mutation.isSuccess && !editor.dirty && (
          <p role="status" className="text-sm text-positive-text">
            Календарь сохранён.
          </p>
        )}
      </section>
    </Card>
  )
}

function CalendarEntryForm({
  companyId,
  plan,
  season,
  initial,
  index,
  disabled,
  onApply,
  onCancel,
}: {
  companyId: string
  plan: Plan
  season: CalendarSnapshot
  initial: CalendarEntry
  index: number | null
  disabled: boolean
  onApply: (entry: CalendarEntry) => void
  onCancel: () => void
}) {
  const [entry, setEntry] = useState(initial)
  const [sku, setSku] = useState(initial.marketplaceSku ?? '')
  const search = useDeferredValue(sku.trim())
  const catalog = useCatalogSkus(companyId, plan.marketplaceAccountId, search)
  const suggestions = useId()
  const normalized = {
    ...entry,
    marketplaceSku: sku.trim() === '' ? null : sku.trim(),
  }
  const problem = calendarEntryProblem(normalized, season)
  return (
    <form
      className="flex flex-col gap-3 rounded-md border border-border-default p-4"
      onSubmit={(event) => {
        event.preventDefault()
        if (!disabled && problem === null) onApply(normalized)
      }}
    >
      <fieldset className="flex flex-col gap-3" disabled={disabled}>
        <legend className="mb-3 font-semibold">
          {index === null ? 'Новое событие' : `Изменение события ${index + 1}`}
        </legend>
        <label className="flex flex-col gap-1 text-sm">
          Вид события
          <select
            className="h-9 rounded-md border border-border-default bg-surface-raised px-3 focus:outline-2 focus:outline-border-focus"
            value={entry.kind}
            onChange={(event) => {
              const kind = event.target.value
              if (
                kind === 'sales' ||
                kind === 'replenishment' ||
                kind === 'replenishment_stop'
              )
                setEntry({
                  ...entry,
                  kind,
                  dateTo: kind === 'sales' ? entry.dateTo : entry.dateFrom,
                })
            }}
          >
            {Object.entries(calendarKindLabels).map(([kind, label]) => (
              <option key={kind} value={kind}>
                {label}
              </option>
            ))}
          </select>
        </label>
        <Input
          label="SKU события"
          value={sku}
          list={suggestions}
          hint="Пусто — весь план. Введите SKU, уже добавленный в этот план; принадлежность проверяется при сохранении. Подсказки — поиск по каталогу."
          onChange={(event) => {
            setSku(event.target.value)
          }}
        />
        <datalist id={suggestions}>
          {catalog.data?.items.map((item) => (
            <option key={item.marketplaceSku} value={item.marketplaceSku}>
              {item.name ?? item.marketplaceSku}
            </option>
          ))}
        </datalist>
        {catalog.isError && (
          <p className="text-xs text-text-muted">
            Подсказки каталога недоступны. Можно ввести SKU вручную.
          </p>
        )}
        <div className="flex flex-wrap gap-3">
          <Input
            label="Дата начала события"
            type="date"
            required
            min={season.seasonStart.date}
            max={season.seasonEnd.date}
            value={entry.dateFrom}
            onChange={(event) => {
              const dateFrom = event.target.value
              setEntry({
                ...entry,
                dateFrom,
                dateTo: entry.kind === 'sales' ? entry.dateTo : dateFrom,
              })
            }}
          />
          {entry.kind === 'sales' && (
            <Input
              label="Дата окончания события"
              type="date"
              required
              min={season.seasonStart.date}
              max={season.seasonEnd.date}
              value={entry.dateTo}
              onChange={(event) => {
                setEntry({ ...entry, dateTo: event.target.value })
              }}
            />
          )}
        </div>
        {problem !== null && (
          <p role="alert" className="text-sm text-negative-text">
            {problem}
          </p>
        )}
        <div>
          <Button type="submit" size="compact" disabled={problem !== null}>
            Применить событие
          </Button>
        </div>
      </fieldset>
      <div>
        <Button type="button" size="compact" variant="ghost" onClick={onCancel}>
          Отменить ввод события
        </Button>
      </div>
    </form>
  )
}
