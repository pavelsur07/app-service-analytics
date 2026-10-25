import {
  Check,
  CircleAlert,
  CircleDashed,
  Clock,
  LoaderCircle,
  Minus,
  RotateCw,
  X,
} from 'lucide-react'
import type { ReactNode } from 'react'

import type { CellState } from '../model/coverage'

interface Appearance {
  label: string
  className: string
  icon: ReactNode
}

// Цвет не единственный носитель смысла: у каждого состояния своя иконка
// и подпись (docs/patterns.md, «Данные и статусы»).
export const CELL_APPEARANCE: Record<CellState, Appearance> = {
  attention: {
    label: 'Нужен человек',
    className: 'border-negative-border bg-negative-bg text-negative-text',
    icon: <CircleAlert aria-hidden="true" size={14} />,
  },
  retrying: {
    label: 'Ошибка, повтор',
    className: 'border-warning-border bg-warning-bg text-warning-text',
    icon: <RotateCw aria-hidden="true" size={14} />,
  },
  in_progress: {
    label: 'В работе',
    className: 'border-border-default bg-surface-sunken text-text-secondary',
    icon: <LoaderCircle aria-hidden="true" size={14} />,
  },
  not_ready: {
    label: 'Отчёт не готов',
    className: 'border-border-default bg-surface-sunken text-text-secondary',
    icon: <Clock aria-hidden="true" size={14} />,
  },
  loaded: {
    label: 'Загружено',
    className: 'border-positive-border bg-positive-bg text-positive-text',
    icon: <Check aria-hidden="true" size={14} />,
  },
  partial: {
    label: 'Частично',
    className: 'border-warning-border bg-warning-bg text-warning-text',
    icon: <CircleDashed aria-hidden="true" size={14} />,
  },
  missing: {
    label: 'Не загружено',
    className: 'border-warning-border bg-warning-bg text-warning-text',
    icon: <X aria-hidden="true" size={14} />,
  },
  no_data: {
    label: 'Нет данных',
    className: 'border-border-subtle bg-transparent text-text-faint',
    icon: <Minus aria-hidden="true" size={14} />,
  },
}
