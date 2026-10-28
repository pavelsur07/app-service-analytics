import { useId, useRef, useState } from 'react'
import { flushSync } from 'react-dom'
import { Button } from '../../../../../../packages/ui/src'
import type { CalendarEntry, CalendarSnapshot } from '../lib/calendarDraft'
import {
  CALENDAR_ROW_HEIGHT,
  calendarKeyboardRow,
  calendarWindow,
} from '../lib/calendarWindow'

export const calendarKindLabels: Record<CalendarEntry['kind'], string> = {
  sales: 'Продажи',
  replenishment: 'Подсорт',
  replenishment_stop: 'Стоп подсорт',
}

/** Only the DOM is windowed: the caller retains the entire versioned snapshot. */
export function CalendarEventsTable({
  entries,
  actions,
  label = 'Ручные события',
}: {
  entries: readonly (CalendarEntry | CalendarSnapshot['entries'][number])[]
  actions?: {
    disabled: boolean
    edit: (index: number) => void
    remove: (index: number) => void
  }
  label?: string
}) {
  const viewport = useRef<HTMLDivElement>(null)
  const [scroll, setScroll] = useState(0)
  const [active, setActive] = useState(0)
  const instruction = useId()
  const virtual = entries.length > 200
  const window = calendarWindow(entries.length, scroll)
  return (
    <div className="flex flex-col gap-2">
      <p className="text-sm text-text-muted">
        {label}: {entries.length} из 500
      </p>
      {virtual && (
        <p id={instruction} className="text-xs text-text-muted">
          Прокручивайте список. Клавиатура: ↑/↓, PageUp/PageDown, Home/End —
          переход между событиями.
        </p>
      )}
      <div
        ref={viewport}
        className={
          virtual
            ? 'h-96 overflow-auto rounded-md border border-border-default focus-visible:outline-2 focus-visible:outline-accent-default'
            : 'overflow-x-auto'
        }
        role={virtual ? 'region' : undefined}
        aria-label={virtual ? `${label}: прокручиваемый список` : undefined}
        aria-describedby={virtual ? instruction : undefined}
        tabIndex={virtual ? 0 : undefined}
        onScroll={(event) => {
          if (virtual) setScroll(event.currentTarget.scrollTop)
        }}
        onKeyDown={(event) => {
          if (
            !virtual ||
            event.altKey ||
            event.ctrlKey ||
            event.metaKey ||
            event.shiftKey
          )
            return
          const next = calendarKeyboardRow(event.key, active, entries.length)
          const element = viewport.current
          if (next === null || element === null) return
          event.preventDefault()
          // Render the target row before focusing it, including Home/End jumps.
          const offset = next * CALENDAR_ROW_HEIGHT
          flushSync(() => {
            setScroll(offset)
            setActive(next)
          })
          element.scrollTop = offset
          element
            .querySelector<HTMLTableRowElement>(
              `[data-calendar-index="${next}"]`,
            )
            ?.focus({ preventScroll: true })
        }}
      >
        <table
          className="w-full text-sm"
          aria-label={label}
          aria-rowcount={entries.length + 1}
        >
          <thead
            className={
              virtual ? 'sticky top-0 z-10 bg-surface-raised' : undefined
            }
          >
            <tr
              aria-rowindex={1}
              className="border-b border-border-default text-left text-xs text-text-muted"
            >
              <th scope="col" className="px-3 py-2 whitespace-nowrap">
                Событие
              </th>
              <th scope="col" className="px-3 py-2 whitespace-nowrap">
                Область
              </th>
              <th scope="col" className="px-3 py-2 whitespace-nowrap">
                Дата или интервал
              </th>
              {actions !== undefined && (
                <th scope="col" className="px-3 py-2">
                  Действия
                </th>
              )}
            </tr>
          </thead>
          <tbody>
            {window.top > 0 && (
              <tr aria-hidden="true">
                <td
                  colSpan={actions === undefined ? 3 : 4}
                  className="p-0"
                  style={{ height: window.top }}
                />
              </tr>
            )}
            {entries.slice(window.start, window.end).map((entry, offset) => {
              const index = window.start + offset
              return (
                <tr
                  key={entry.id ?? `new-${index}`}
                  aria-rowindex={index + 2}
                  data-calendar-index={index}
                  tabIndex={virtual ? -1 : undefined}
                  onFocus={() => {
                    setActive(index)
                  }}
                  className={
                    virtual
                      ? 'h-16 border-b border-border-subtle focus-visible:outline-2 focus-visible:outline-accent-default'
                      : 'border-b border-border-subtle'
                  }
                >
                  <th
                    scope="row"
                    className="px-3 py-2 text-left font-medium whitespace-nowrap"
                  >
                    {calendarKindLabels[entry.kind]}
                  </th>
                  <td className="px-3 py-2 whitespace-nowrap">
                    {entry.marketplaceSku ?? 'Весь план'}
                  </td>
                  <td className="px-3 py-2 whitespace-nowrap">
                    <time dateTime={entry.dateFrom}>{entry.dateFrom}</time>
                    {entry.kind === 'sales' &&
                      entry.dateTo !== entry.dateFrom && (
                        <>
                          {' '}
                          — <time dateTime={entry.dateTo}>{entry.dateTo}</time>
                        </>
                      )}
                  </td>
                  {actions !== undefined && (
                    <td className="px-3 py-2">
                      <div className="flex gap-2">
                        <Button
                          type="button"
                          size="compact"
                          variant="secondary"
                          aria-label={`Изменить событие ${index + 1}`}
                          disabled={actions.disabled}
                          onClick={() => {
                            actions.edit(index)
                          }}
                        >
                          Изменить
                        </Button>
                        <Button
                          type="button"
                          size="compact"
                          variant="ghost"
                          aria-label={`Удалить событие ${index + 1}`}
                          disabled={actions.disabled}
                          onClick={() => {
                            actions.remove(index)
                          }}
                        >
                          Удалить
                        </Button>
                      </div>
                    </td>
                  )}
                </tr>
              )
            })}
            {window.bottom > 0 && (
              <tr aria-hidden="true">
                <td
                  colSpan={actions === undefined ? 3 : 4}
                  className="p-0"
                  style={{ height: window.bottom }}
                />
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
