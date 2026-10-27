import { describe, expect, it } from 'vitest'
import type { components } from '../../../api/schema'
import {
  calendarEntryProblem,
  calendarWriteCommand,
  startCalendarDraft,
} from './calendarDraft'

type Snapshot = Omit<
  components['schemas']['SalesPlanCalendarResponse'],
  'nextCursor'
>
const snapshot: Snapshot = {
  planId: 'plan',
  version: 7,
  status: 'draft',
  seasonStart: { kind: 'season_start', date: '2026-07-01' },
  seasonEnd: { kind: 'season_end', date: '2026-07-31' },
  entries: [
    {
      id: 'saved',
      marketplaceSku: null,
      kind: 'sales',
      dateFrom: '2026-07-01',
      dateTo: '2026-07-31',
    },
  ],
}

describe('calendar full local draft', () => {
  it('captures identity and version, accepts coincident events and never rebases after conflict or background refresh', () => {
    const draft = startCalendarDraft(snapshot)
    draft.entries.push({
      marketplaceSku: 'sku',
      kind: 'replenishment',
      dateFrom: '2026-07-01',
      dateTo: '2026-07-01',
    })
    expect(snapshot.entries).toHaveLength(1)
    expect(calendarWriteCommand(draft, snapshot, true)).toEqual({
      version: 7,
      entries: draft.entries,
    })
    expect(
      calendarWriteCommand(draft, { ...snapshot, version: 8 }, true),
    ).toBeNull()
    expect(calendarWriteCommand(draft, snapshot, false)).toBeNull()
    expect(calendarWriteCommand(draft, undefined, true)).toBeNull()
    expect(draft.base.version).toBe(7)
    expect(draft.entries[0]?.id).toBe('saved')
    expect(draft.entries[1]?.id).toBeUndefined()
    const cancelled = startCalendarDraft({
      ...snapshot,
      version: 8,
      entries: [],
    })
    expect(calendarWriteCommand(cancelled, cancelled.base, true)).toEqual({
      version: 8,
      entries: [],
    })
  })

  it('rejects closed/foreign snapshots and 501 entries without excluding a full 500 set', () => {
    const draft = startCalendarDraft(snapshot)
    draft.entries = Array.from({ length: 500 }, () => ({
      kind: 'sales',
      marketplaceSku: null,
      dateFrom: '2026-07-01',
      dateTo: '2026-07-01',
    }))
    expect(calendarWriteCommand(draft, snapshot, true)?.entries).toHaveLength(
      500,
    )
    expect(
      calendarWriteCommand(draft, { ...snapshot, status: 'approved' }, true),
    ).toBeNull()
    expect(
      calendarWriteCommand(draft, { ...snapshot, planId: 'foreign' }, true),
    ).toBeNull()
    draft.entries.push({
      kind: 'sales',
      marketplaceSku: null,
      dateFrom: '2026-07-01',
      dateTo: '2026-07-01',
    })
    expect(calendarWriteCommand(draft, snapshot, true)).toBeNull()
  })

  it('validates strict season dates, sales intervals and point semantics', () => {
    const valid = {
      marketplaceSku: null,
      kind: 'sales' as const,
      dateFrom: '2026-07-01',
      dateTo: '2026-07-31',
    }
    expect(calendarEntryProblem(valid, snapshot)).toBeNull()
    expect(
      calendarEntryProblem({ ...valid, dateFrom: '2026-02-30' }, snapshot),
    ).toContain('корректные')
    expect(
      calendarEntryProblem({ ...valid, dateTo: '2026-06-30' }, snapshot),
    ).toContain('раньше')
    expect(
      calendarEntryProblem({ ...valid, dateFrom: '2026-06-30' }, snapshot),
    ).toContain('сезона')
    expect(
      calendarEntryProblem({ ...valid, dateTo: '2026-08-01' }, snapshot),
    ).toContain('сезона')
    for (const kind of ['replenishment', 'replenishment_stop'] as const) {
      expect(calendarEntryProblem({ ...valid, kind }, snapshot)).toContain(
        'одну дату',
      )
      expect(
        calendarEntryProblem(
          { ...valid, kind, dateTo: valid.dateFrom },
          snapshot,
        ),
      ).toBeNull()
    }
    expect(
      calendarEntryProblem({ ...valid, marketplaceSku: ' ' }, snapshot),
    ).toContain('SKU')
  })
})
