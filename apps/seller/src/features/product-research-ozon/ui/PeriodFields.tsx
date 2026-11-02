import { Button, Input } from '../../../../../../packages/ui/src'
import type { Period, Preview } from '../model/types'
import {
  displayedPeriods,
  previousYear,
  type Selection,
} from '../lib/selection'

export function PeriodFields({
  selection,
  preview,
  today,
  onChange,
  allowShortcuts = true,
}: {
  selection: Selection
  preview: Preview | undefined
  today: string
  onChange: (selection: Selection) => void
  allowShortcuts?: boolean
}) {
  const periods = displayedPeriods(selection, preview)
  const change = (index: number, value: Partial<Period>) => {
    onChange({
      periods: periods.map((p, i) => (i === index ? { ...p, ...value } : p)),
    })
  }
  return (
    <fieldset className="flex min-w-0 flex-col gap-3">
      <legend className="mb-2 text-sm font-semibold">
        Периоды исследования
      </legend>
      {allowShortcuts && (
        <div className="flex flex-wrap gap-2">
          {([3, 6, 12] as const).map((months) => (
            <Button
              key={months}
              type="button"
              variant="secondary"
              size="compact"
              onClick={() => {
                onChange({ shortcut: { kind: 'last_closed_months', months } })
              }}
            >
              {months} мес.
            </Button>
          ))}
          <Button
            type="button"
            variant="ghost"
            size="compact"
            disabled={periods.length === 0}
            onClick={() => {
              onChange({ periods: [...periods, ...periods.map(previousYear)] })
            }}
          >
            Тот же период год назад
          </Button>
        </div>
      )}
      {periods.map((period, index) => (
        <div
          key={index}
          className="grid gap-2 rounded-md border border-border-subtle p-3 sm:grid-cols-2 lg:grid-cols-4"
        >
          <Input
            label={`Период ${index + 1}: с`}
            type="month"
            value={period.fromMonth}
            min="2000-01"
            max={today.slice(0, 7)}
            required
            onChange={(e) => {
              change(index, { fromMonth: e.target.value })
            }}
          />
          <Input
            label={`Период ${index + 1}: по`}
            type="month"
            value={period.toMonth}
            min={period.fromMonth || '2000-01'}
            max={today.slice(0, 7)}
            required
            onChange={(e) => {
              change(index, { toMonth: e.target.value })
            }}
          />
          <Input
            label={`Название периода ${index + 1}`}
            value={period.name}
            maxLength={100}
            placeholder="Например, весенний сезон"
            onChange={(e) => {
              change(index, { name: e.target.value })
            }}
          />
          <div className="flex items-end">
            <Button
              type="button"
              variant="ghost"
              size="compact"
              disabled={periods.length === 1}
              onClick={() => {
                onChange({ periods: periods.filter((_, i) => i !== index) })
              }}
            >
              Удалить период {index + 1}
            </Button>
          </div>
        </div>
      ))}
      <div>
        <Button
          type="button"
          variant="secondary"
          size="compact"
          disabled={periods.length === 0 || periods.length >= 24}
          onClick={() => {
            onChange({
              periods: [...periods, { fromMonth: '', toMonth: '', name: '' }],
            })
          }}
        >
          + Период
        </Button>
      </div>
    </fieldset>
  )
}
