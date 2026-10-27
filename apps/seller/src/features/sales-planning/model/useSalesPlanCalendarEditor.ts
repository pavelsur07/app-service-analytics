import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError } from '../../../api/ApiError'
import {
  calendarWriteCommand,
  startCalendarDraft,
  type CalendarDraft,
  type CalendarEntry,
} from '../lib/calendarDraft'
import { calendarQueryOptions } from './useSalesPlanCalendar'
import { calendarMutationOptions } from './useSetSalesPlanCalendar'

export function useSalesPlanCalendarEditor(
  companyId: string,
  planId: string,
  headerVersion: number,
) {
  const client = useQueryClient()
  const query = useQuery(calendarQueryOptions(companyId, planId, headerVersion))
  const mutation = useMutation(
    calendarMutationOptions(client, companyId, planId),
  )
  const [draft, setDraft] = useState<CalendarDraft | null>(null)
  const [editing, setEditing] = useState<{
    index: number | null
    entry: CalendarEntry
  } | null>(null)
  const snapshot = draft?.base ?? query.data
  const entries = draft?.entries ?? query.data?.entries ?? []
  const current = query.isSuccess ? query.data : undefined
  const status = query.data?.status ?? snapshot?.status
  const editable = status === 'draft'
  const canChangeEntries =
    editable &&
    !mutation.isPending &&
    (draft !== null || (query.isSuccess && !query.isFetching))
  const conflict =
    mutation.error instanceof ApiError && mutation.error.status === 409
  const changed =
    draft !== null &&
    current !== undefined &&
    (current.version !== draft.base.version ||
      current.status !== draft.base.status)
  const command =
    draft === null
      ? null
      : calendarWriteCommand(
          draft,
          current,
          !query.isFetching &&
            !mutation.isPending &&
            !conflict &&
            editing === null,
        )
  const capture = () =>
    draft ??
    (current === undefined || query.isFetching
      ? null
      : startCalendarDraft(current))
  return {
    query,
    mutation,
    snapshot,
    entries,
    editing,
    editable,
    canChangeEntries,
    conflict,
    changed,
    dirty: draft !== null,
    canSave: command !== null,
    startEditing: (index: number | null) => {
      const next = capture()
      if (
        !canChangeEntries ||
        next === null ||
        (index === null && next.entries.length >= 500)
      )
        return
      const entry =
        index === null
          ? {
              kind: 'sales' as const,
              marketplaceSku: null,
              dateFrom: next.base.seasonStart.date,
              dateTo: next.base.seasonStart.date,
            }
          : next.entries[index]
      if (entry === undefined) return
      setDraft(next)
      setEditing({ index, entry })
    },
    apply: (entry: CalendarEntry) => {
      if (draft === null || editing === null || !editable || mutation.isPending)
        return
      setDraft({
        ...draft,
        entries:
          editing.index === null
            ? [...draft.entries, entry]
            : draft.entries.map((row, index) =>
                index === editing.index ? entry : row,
              ),
      })
      setEditing(null)
    },
    cancelEntry: () => {
      setEditing(null)
    },
    remove: (index: number) => {
      const next = capture()
      if (!canChangeEntries || next === null || editing !== null) return
      setDraft({
        ...next,
        entries: next.entries.filter((_, row) => row !== index),
      })
    },
    cancelLocal: () => {
      // Explicitly discard only after a complete successful reread is available.
      if (current === undefined || query.isFetching || mutation.isPending)
        return
      setDraft(null)
      setEditing(null)
      mutation.reset()
    },
    save: () => {
      if (command === null) return
      mutation.mutate(command, {
        onSuccess: () => {
          setDraft(null)
          setEditing(null)
        },
      })
    },
  }
}
