import { describe, expect, it } from 'vitest'
import type { components } from './schema'
import { apiDelete, apiPostForm } from './client'
import { http, server } from '../../tests/msw/server'

type DailyPlanItem = components['schemas']['DailyPlanItemResponse']

const COMPANY_ID = '019ffe00-0000-7000-8000-000000000001'
const ACCOUNT_ID = '019ffe00-0000-7000-8000-000000000002'

describe('apiDelete', () => {
  it('передаёт expectedVersion и читает новую версию снятого плана', async () => {
    const path = `/api/companies/${COMPANY_ID}/planning/accounts/${ACCOUNT_ID}/skus/SKU-1/plan/2026-09-21`
    server.use(
      http.delete(
        '/api/companies/{companyId}/planning/accounts/{accountId}/skus/{sku}/plan/{date}',
        async ({ request, response }) => {
          expect(await request.json()).toEqual({ expectedVersion: 3 })

          return response(200).json({
            date: '2026-09-21',
            quantity: null,
            version: 4,
          })
        },
      ),
    )

    await expect(
      apiDelete<DailyPlanItem>(`http://localhost${path}`, {
        expectedVersion: 3,
      }),
    ).resolves.toEqual({
      date: '2026-09-21',
      quantity: null,
      version: 4,
    })
  })

  it('сохраняет прежний DELETE без тела и ответа', async () => {
    const path = `/api/companies/${COMPANY_ID}/connections/${ACCOUNT_ID}`
    server.use(
      http.delete(
        '/api/companies/{companyId}/connections/{marketplaceAccountId}',
        ({ request, response }) => {
          expect(request.headers.has('Content-Type')).toBe(false)

          return response(204).empty()
        },
      ),
    )

    await expect(apiDelete(`http://localhost${path}`)).resolves.toBeUndefined()
  })
})

describe('apiPostForm', () => {
  it('передаёт XLSX без ручной границы Content-Type', async () => {
    server.use(
      http.post(
        '/api/companies/{companyId}/planning/accounts/{accountId}/imports/preview',
        async ({ request, response }) => {
          expect(request.headers.get('Content-Type')).toContain(
            'multipart/form-data; boundary=',
          )
          const data = await request.formData()
          expect(data.get('file')).toBeInstanceOf(File)

          return response(200).json({
            previewId: 'preview-id',
            expiresAt: '2026-09-22T12:00:00+00:00',
            summary: { total: 0, new: 0, changed: 0, unchanged: 0 },
            items: [],
            issues: [],
          })
        },
      ),
    )
    const form = new FormData()
    form.append(
      'file',
      new File(['xlsx'], 'plan.xlsx', {
        type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      }),
    )

    await expect(
      apiPostForm<{ previewId: string }>(
        `http://localhost/api/companies/${COMPANY_ID}/planning/accounts/${ACCOUNT_ID}/imports/preview`,
        form,
      ),
    ).resolves.toEqual({
      previewId: 'preview-id',
      expiresAt: '2026-09-22T12:00:00+00:00',
      summary: { total: 0, new: 0, changed: 0, unchanged: 0 },
      items: [],
      issues: [],
    })
  })
})
