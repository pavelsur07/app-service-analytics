import { expect, type Page } from '@playwright/test'
import type { components } from '../../src/api/schema'

type Project = components['schemas']['ProjectResponse']
type Progress = components['schemas']['ProjectProgressResponse']
type Month = components['schemas']['ProjectMonthResponse']
type Preview = components['schemas']['PreviewResponse']
type Period = components['schemas']['PeriodResponse']
type Run = components['schemas']['RunResponse']

const stamp = '2026-04-15T09:00:00+00:00'
const path = 'Одежда/Женская одежда/Хиджабы и тюрбаны женские'
const uuid = (n: number) =>
  `019a0000-0000-7000-8000-${String(n).padStart(12, '0')}`
const period = (year: number): Period => ({
  fromMonth: `${year}-01`,
  toMonth: `${year}-03`,
  name: 'Весенний сезон',
})
function month(year: number, number: number): Month {
  return {
    period: {
      month: `${year}-0${number}`,
      dateFrom: `${year}-0${number}-01`,
      dateTo: `${year}-0${number}-${number === 2 ? 28 : 31}`,
      days: number === 2 ? 28 : 31,
      partial: false,
    },
    status: 'missing',
    rowCount: null,
    errorCode: null,
    origin: null,
    collectedAt: null,
    usedAt: null,
  }
}
function preview(years: number[]): Preview {
  return {
    periods: years.map(period),
    months: years.flatMap((y) => [1, 2, 3].map((m) => month(y, m).period)),
    monthCount: years.length * 3,
    summary: years.map((y) => `янв–мар ${y}`).join(', '),
    calendarDate: '2026-04-15',
    timezone: 'Europe/Moscow',
    suggestedName: 'Хиджабы и тюрбаны женские · весенний сезон',
    cachedMonthCount: 0,
  }
}
function ready(m: Month, cached = false): Month {
  return {
    ...m,
    status: 'ready',
    rowCount: m.period.month.endsWith('02') ? 0 : 10,
    origin: cached ? 'cache' : 'api',
    collectedAt: stamp,
    usedAt: stamp,
    errorCode: null,
  }
}
/** Synthetic research source only. Authentication/company shell remain real.
 * Every payload is a generated OpenAPI type; counters assert boundaries and intent.
 */
export async function researchFixture(
  page: Page,
  company: string,
  partialFailure = false,
) {
  let project: Project | null = null
  let progress: Progress | null = null
  let creates = 0
  let starts = 0
  let progressReads = 0
  let foreignReads = 0
  let generation = 0
  let completionHeld = false
  let otherEnabled = false
  let loseCreateReply = false
  let waitingReads = 0
  let includePrevious = false
  let lostOnce = false
  let retryIntent: unknown = null
  let activeMonths: Month[] = []
  await page.route(
    '**/api/companies/*/product-research-ozon/**',
    async (route) => {
      const request = route.request()
      const url = new URL(request.url())
      const suffix = url.pathname.split('/product-research-ozon')[1] ?? ''
      const actorCompany = url.pathname.split('/')[3]
      const send = async (body: unknown, status = 200) =>
        route.fulfill({ status, json: body })
      if (suffix === '/availability')
        return send({
          enabled: actorCompany === company || otherEnabled,
        } satisfies components['schemas']['ResearchAvailabilityResponse'])
      if (actorCompany !== company) {
        foreignReads++
        if (
          otherEnabled &&
          suffix === '/projects' &&
          request.method() === 'GET'
        )
          return send({
            items: [],
            nextCursor: null,
          } satisfies components['schemas']['ProjectsResponse'])
        return route.abort('failed')
      }
      if (suffix === '/categories') {
        expect(
          url.searchParams.get('q')?.match(/\p{L}/gu)?.length,
        ).toBeGreaterThanOrEqual(3)
        return send({
          items: [{ path }],
          revision: 1,
          generatedAt: stamp,
          expiresAt: '2026-04-16T09:00:00+00:00',
        } satisfies components['schemas']['ResearchCategoriesResponse'])
      }
      if (suffix === '/preview') {
        expect(request.headers()['x-conwix-request']).toBe('1')
        const body: unknown = request.postDataJSON()
        expect(body).toMatchObject({ includeCurrentMonth: false })
        const raw = JSON.stringify(body)
        return send(preview(raw.includes('2025-') ? [2025, 2026] : [2026]))
      }
      if (suffix === '/projects' && request.method() === 'GET') {
        return send({
          items:
            project === null
              ? []
              : [
                  {
                    id: project.id,
                    categoryPath: path,
                    name: project.name,
                    periodCount: project.periodCount,
                    version: project.version,
                    createdAt: stamp,
                    status: progress?.status ?? 'draft',
                  },
                ],
          nextCursor: null,
        } satisfies components['schemas']['ProjectsResponse'])
      }
      if (suffix === '/projects' && request.method() === 'POST') {
        creates++
        expect(creates).toBe(1)
        const body: Record<string, unknown> = request.postDataJSON()
        expect(body).toMatchObject({
          categoryPath: path,
          previewCalendarDate: '2026-04-15',
          includeCurrentMonth: false,
        })
        includePrevious = JSON.stringify(body).includes('2025-')
        const years = includePrevious ? [2025, 2026] : [2026]
        project = {
          id: uuid(1),
          categoryPath: path,
          categoryRevision: 1,
          name:
            typeof body.name === 'string' && body.name !== ''
              ? body.name
              : 'Весенний сезон Ozon',
          comment: '',
          includeCurrentMonth: false,
          periodCount: years.length,
          version: 1,
          createdByUserId: uuid(5),
          createdAt: stamp,
          updatedAt: stamp,
          status: 'draft',
          fbs: true,
          currency: 'RUB',
          preview: preview(years),
        }
        progress = {
          projectId: project.id,
          version: 1,
          status: 'draft',
          lastRunId: null,
          runRevision: null,
          months: years.flatMap((y) => [1, 2, 3].map((m) => month(y, m))),
        }
        if (loseCreateReply) return route.abort('failed')
        return send(project, 201)
      }
      if (project === null || progress === null)
        throw new Error(`Unexpected fixture request ${suffix}`)
      if (suffix === `/projects/${project.id}`)
        return send({ ...project, status: progress.status })
      if (suffix === `/projects/${project.id}/progress`) {
        progressReads++
        if (progress.status === 'loading') {
          waitingReads++
          if (waitingReads >= 2 && !completionHeld) {
            const failed = partialFailure && generation === 1
            const active = new Set(activeMonths.map((m) => m.period.month))
            const completed = progress.months.map((m): Month => {
              if (!active.has(m.period.month)) return m
              if (failed && m.period.month === '2026-03')
                return {
                  ...m,
                  status: 'error',
                  errorCode: 'credentials_rejected',
                }
              return ready(m, generation > 1)
            })
            progress = {
              ...progress,
              status: completed.every((m) => m.status === 'ready')
                ? 'loaded'
                : 'partially_loaded',
              runRevision: 9,
              months: completed,
            }
          }
        }
        return send(progress)
      }
      if (suffix === `/projects/${project.id}/runs`) {
        starts++
        const body: Record<string, unknown> = request.postDataJSON()
        expect(body.requestId).toMatch(
          /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
        )
        expect(body.previewCalendarDate).toBe('2026-04-15')
        if (retryIntent !== null) expect(body).toEqual(retryIntent)
        else {
          expect(body.expectedVersion).toBe(project.version)
          generation++
          project = { ...project, version: project.version + 1 }
          waitingReads = 0
          activeMonths = progress.months
            .filter((m) => m.status !== 'ready')
            .map((m) => ({ ...m, status: 'queued', errorCode: null }))
          progress = {
            ...progress,
            status: 'loading',
            version: project.version,
            lastRunId: uuid(10 + generation),
            runRevision: 1,
            months: progress.months.map((m) =>
              m.status === 'ready'
                ? m
                : { ...m, status: 'queued', errorCode: null },
            ),
          }
        }
        if (partialFailure && generation === 2 && !lostOnce) {
          lostOnce = true
          retryIntent = body
          return route.abort('failed')
        }
        retryIntent = null
        const run: Run = {
          id: progress.lastRunId ?? uuid(10),
          projectId: project.id,
          status: 'queued',
          revision: 1,
          delivered: false,
          createdAt: stamp,
          months: activeMonths.map((m) => ({
            ...m,
            status: 'queued',
            snapshotId: null,
          })),
        }
        return send(run, 202)
      }
      if (suffix === `/projects/${project.id}/periods`) {
        const body: Record<string, unknown> = request.postDataJSON()
        expect(body.expectedVersion).toBe(project.version)
        expect(body.periods).toEqual([period(2025)])
        includePrevious = true
        project = {
          ...project,
          version: project.version + 1,
          periodCount: 2,
          preview: preview([2025, 2026]),
        }
        progress = {
          ...progress,
          version: project.version,
          status:
            progress.status === 'loading' ? 'loading' : 'partially_loaded',
          months: [...[1, 2, 3].map((m) => month(2025, m)), ...progress.months],
        }
        return send({ ...project, status: progress.status })
      }
      if (suffix === `/projects/${project.id}/copy`) {
        const body: Record<string, unknown> = request.postDataJSON()
        expect(body.expectedVersion).toBe(project.version)
        project = {
          ...project,
          id: uuid(2),
          name: 'Копия исследования',
          status: 'draft',
          version: 1,
          preview: preview(includePrevious ? [2025, 2026] : [2026]),
        }
        progress = {
          projectId: project.id,
          version: 1,
          status: 'draft',
          lastRunId: null,
          runRevision: null,
          months: (includePrevious ? [2025, 2026] : [2026]).flatMap((y) =>
            [1, 2, 3].map((m) => month(y, m)),
          ),
        }
        return send(project, 201)
      }
      throw new Error(`Unmapped research fixture ${request.method()} ${suffix}`)
    },
  )
  return {
    loseCreateReply: () => {
      loseCreateReply = true
    },
    enableOther: () => {
      otherEnabled = true
    },
    disableOther: () => {
      otherEnabled = false
    },
    hold: () => {
      completionHeld = true
    },
    release: () => {
      completionHeld = false
    },
    creates: () => creates,
    starts: () => starts,
    reads: () => progressReads,
    foreignReads: () => foreignReads,
    runMonths: () => activeMonths.length,
  }
}
