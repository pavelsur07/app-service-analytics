import { SlidersHorizontal } from 'lucide-react'
import {
  type ReactNode,
  type RefObject,
  useEffect,
  useRef,
  useState,
} from 'react'

import {
  addDays,
  COMPARE_MODES,
  type CompareMode,
  type DateRange,
  dayCountLabel,
  EARLIEST_DATE,
  isPresetAvailable,
  isValidDate,
  PRESETS,
  type PresetId,
  rangeLabel,
} from '../lib/period'
import { WIDGETS, type WidgetId } from '../lib/widgets'

// Сегментный контрол макета: высота 36, общая рамка, разделители 1px,
// активный — accent-subtle с accent-hover и полужирным.
const SEGMENT =
  'cursor-pointer whitespace-nowrap px-3 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent-default disabled:cursor-not-allowed disabled:text-text-disabled'
const SEGMENT_ON = 'bg-accent-subtle font-semibold text-accent-hover'
const SEGMENT_OFF =
  'bg-surface-raised font-medium text-text-secondary hover:bg-surface-hover'

function Segments({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div
      aria-label={label}
      className="flex h-9 w-max divide-x divide-border-default overflow-hidden rounded-md border border-border-default"
      role="group"
    >
      {children}
    </div>
  )
}

const DATE_INPUT =
  'h-9 rounded-md border border-border-default bg-surface-raised px-2 text-sm text-text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default'

export interface PeriodBarProps {
  today: string
  preset: PresetId
  compare: CompareMode
  range: DateRange
  comparison: DateRange
  hidden: readonly WidgetId[]
  settingsOpen: boolean
  /**
   * Кнопка «Виджеты»: фокус возвращается на неё, когда панель закрыта
   * или виджет скрыт крестиком, — иначе он уходит в начало документа
   * вместе с размонтированным элементом.
   */
  settingsTrigger: RefObject<HTMLButtonElement | null>
  onPreset: (preset: PresetId) => void
  onCustom: (range: DateRange) => void
  onCompare: (compare: CompareMode) => void
  onSettingsOpen: (open: boolean) => void
  onHidden: (hidden: readonly WidgetId[]) => void
}

export function PeriodBar({
  today,
  preset,
  compare,
  range,
  comparison,
  hidden,
  settingsOpen,
  settingsTrigger,
  onPreset,
  onCustom,
  onCompare,
  onSettingsOpen,
  onHidden,
}: PeriodBarProps) {
  const shown = WIDGETS.length - hidden.length
  const maxDate = addDays(today, -1)

  return (
    <section
      aria-label="Период и сравнение"
      className="relative flex flex-wrap items-center gap-3 rounded-xl border border-border-default bg-surface-raised px-4 py-3 shadow-card"
    >
      <div className="max-w-full overflow-x-auto">
        <Segments label="Период">
          {PRESETS.map((item) => {
            const available = isPresetAvailable(item.id, today)

            return (
              <button
                aria-pressed={preset === item.id}
                className={`${SEGMENT} text-sm ${preset === item.id ? SEGMENT_ON : SEGMENT_OFF}`}
                disabled={!available}
                key={item.id}
                onClick={() => {
                  onPreset(item.id)
                }}
                title={
                  available
                    ? undefined
                    : 'Сегодня первое число — полных дней ещё нет'
                }
                type="button"
              >
                {item.label}
              </button>
            )
          })}
        </Segments>
      </div>

      {preset === 'custom' ? (
        <div className="flex items-center gap-2">
          <DateField
            label="С"
            max={maxDate}
            onCommit={(from) => {
              onCustom({ from, to: range.to })
            }}
            value={range.from}
          />
          <DateField
            label="По"
            max={maxDate}
            onCommit={(to) => {
              onCustom({ from: range.from, to })
            }}
            value={range.to}
          />
        </div>
      ) : null}

      <div className="flex items-center gap-2">
        <span className="text-xs text-text-muted">Сравнить с</span>
        <Segments label="Сравнивать с">
          {COMPARE_MODES.map((mode) => (
            <button
              aria-pressed={compare === mode.id}
              className={`${SEGMENT} text-sm ${compare === mode.id ? SEGMENT_ON : SEGMENT_OFF}`}
              key={mode.id}
              onClick={() => {
                onCompare(mode.id)
              }}
              type="button"
            >
              {mode.label}
            </button>
          ))}
        </Segments>
      </div>

      <div className="ml-auto flex flex-wrap items-center gap-4">
        <div className="flex flex-col items-end gap-0.5 tabular-nums">
          <span className="text-xs font-medium text-text-primary">
            {rangeLabel(range)} · {dayCountLabel(range)}
          </span>
          <span className="text-xs text-text-muted">
            vs {rangeLabel(comparison)}
          </span>
        </div>
        <button
          aria-expanded={settingsOpen}
          aria-haspopup="dialog"
          className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-border-default bg-surface-raised px-3 text-sm font-medium whitespace-nowrap text-text-secondary hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default"
          onClick={() => {
            onSettingsOpen(!settingsOpen)
          }}
          ref={settingsTrigger}
          type="button"
        >
          <SlidersHorizontal aria-hidden="true" size={16} />
          Виджеты · {shown} из {WIDGETS.length}
        </button>
      </div>

      {settingsOpen ? (
        <WidgetSettings
          hidden={hidden}
          onClose={() => {
            onSettingsOpen(false)
            settingsTrigger.current?.focus()
          }}
          onHidden={onHidden}
        />
      ) : null}
    </section>
  )
}

function WidgetSettings({
  hidden,
  onClose,
  onHidden,
}: {
  hidden: readonly WidgetId[]
  onClose: () => void
  onHidden: (hidden: readonly WidgetId[]) => void
}) {
  const dialog = useRef<HTMLDivElement>(null)

  // Фокус в панель при открытии и Esc для закрытия: с клавиатуры иначе
  // панель не закрыть, не обойдя всю страницу.
  useEffect(() => {
    dialog.current?.focus()
  }, [])

  return (
    <>
      <button
        aria-label="Закрыть настройки виджетов"
        className="fixed inset-0 z-30 cursor-default"
        onClick={onClose}
        tabIndex={-1}
        type="button"
      />
      <div
        aria-label="Настройка виджетов"
        className="absolute top-14 right-4 z-40 flex w-85 max-w-full flex-col gap-0.5 rounded-lg border border-border-default bg-surface-raised p-1.5 shadow-popover focus-visible:outline-none"
        onKeyDown={(event) => {
          if (event.key === 'Escape') {
            onClose()
          }
        }}
        ref={dialog}
        role="dialog"
        tabIndex={-1}
      >
        <div className="flex items-baseline justify-between px-2.5 pt-2 pb-1.5">
          <span className="text-sm font-semibold">Виджеты на главной</span>
          <span className="text-xs text-text-muted">
            {WIDGETS.length - hidden.length} из {WIDGETS.length}
          </span>
        </div>
        {WIDGETS.map((widget) => {
          const on = !hidden.includes(widget.id)

          return (
            <button
              aria-checked={on}
              className="flex w-full cursor-pointer items-center gap-3 rounded-md px-2.5 py-2 text-left text-sm font-medium text-text-primary hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default"
              key={widget.id}
              onClick={() => {
                onHidden(
                  on
                    ? [...hidden, widget.id]
                    : hidden.filter((id) => id !== widget.id),
                )
              }}
              role="switch"
              type="button"
            >
              <span className="min-w-0 flex-1">{widget.title}</span>
              <span
                aria-hidden="true"
                className={`relative h-5 w-8 flex-none rounded-full ${on ? 'bg-accent-default' : 'bg-border-strong'}`}
              >
                <span
                  className={`absolute top-0.5 size-4 rounded-full bg-surface-raised shadow-card ${on ? 'left-3.5' : 'left-0.5'}`}
                />
              </span>
            </button>
          )
        })}
        <div className="mt-1 flex gap-2 border-t border-border-subtle px-2.5 pt-2 pb-1.5">
          <button
            className="h-7 cursor-pointer rounded-md border border-border-default bg-surface-raised px-2.5 text-xs font-medium text-text-secondary hover:bg-surface-hover"
            onClick={() => {
              onHidden([])
            }}
            type="button"
          >
            Показать все
          </button>
          <button
            className="h-7 cursor-pointer rounded-md border border-border-default bg-surface-raised px-2.5 text-xs font-medium text-text-secondary hover:bg-surface-hover"
            onClick={() => {
              onHidden(WIDGETS.map((widget) => widget.id))
            }}
            type="button"
          >
            Скрыть все
          </button>
        </div>
      </div>
    </>
  )
}

/**
 * Поле даты своего периода. Применяется по уходу фокуса или Enter,
 * а не на каждое изменение: при наборе года с клавиатуры браузер отдаёт
 * промежуточные даты («0002-…», «0202-…»), и каждая, применённая сразу,
 * обрезалась бы до 2020 года и возвращалась в поле посреди ввода —
 * а заодно уходила бы запросом к API.
 */
function DateField({
  label,
  value,
  max,
  onCommit,
}: {
  label: string
  value: string
  max: string
  onCommit: (date: string) => void
}) {
  const [draft, setDraft] = useState(value)
  const [shown, setShown] = useState(value)
  // Дата пришла извне (обрезка, переворот, другой пресет) — черновик
  // догоняет её в том же рендере. Без перемонтирования поля: смена key
  // сняла бы фокус с input, на котором стоит пользователь.
  if (shown !== value) {
    setShown(value)
    setDraft(value)
  }

  const commit = (): void => {
    if (isValidDate(draft) && draft !== value) {
      onCommit(draft)
    }
    // Применённая дата могла обрезаться до той же, что была: тогда
    // value не меняется, и поле вернётся к действующей дате здесь.
    setDraft(value)
  }

  return (
    <label className="flex items-center gap-1.5 text-xs text-text-muted">
      {label}
      <input
        className={DATE_INPUT}
        max={max}
        min={EARLIEST_DATE}
        onBlur={commit}
        onChange={(event) => {
          setDraft(event.target.value)
        }}
        onKeyDown={(event) => {
          if (event.key === 'Enter') {
            commit()
          }
        }}
        type="date"
        value={draft}
      />
    </label>
  )
}
