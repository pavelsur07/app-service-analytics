import { QueryClient } from '@tanstack/react-query'
import { describe, expect, it } from 'vitest'
import { http, server } from '../../../../tests/msw/server'
import { apiGet } from '../../../api/client'
import { adminQueryKey } from '../../../shared/lib/adminQueryKey'
import { clientAccountQueryOptions } from './useClientAccount'
import type { ClientAccountDetail } from './useClientAccount'

function card(id: string): ClientAccountDetail {
  return {
    company: {
      id,
      name: `Компания ${id}`,
      status: 'active',
      createdAt: '2026-11-01T10:00:00+00:00',
      hasConfirmedUser: false,
    },
    members: [],
    membersTruncated: false,
    connections: [],
    connectionsTruncated: false,
    recentAudit: [],
    auditTruncated: false,
  }
}

describe('карточка компании', () => {
  it('изолирует кэш карточек и инвалидирует их вместе со списком после изменения статуса', async () => {
    const client = new QueryClient()
    const first = clientAccountQueryOptions('first')
    const second = clientAccountQueryOptions('second')
    client.setQueryData(first.queryKey, card('first'))
    client.setQueryData(second.queryKey, card('second'))
    client.setQueryData(adminQueryKey('accounts', { page: 2 }), { items: [] })
    client.setQueryData(adminQueryKey('links'), { items: [] })
    expect(client.getQueryData(first.queryKey)?.company.id).toBe('first')
    expect(client.getQueryData(second.queryKey)?.company.id).toBe('second')
    await client.invalidateQueries({ queryKey: adminQueryKey('accounts') })
    expect(client.getQueryState(first.queryKey)?.isInvalidated).toBe(true)
    expect(client.getQueryState(second.queryKey)?.isInvalidated).toBe(true)
    expect(
      client.getQueryState(adminQueryKey('accounts', { page: 2 }))
        ?.isInvalidated,
    ).toBe(true)
    expect(client.getQueryState(adminQueryKey('links'))?.isInvalidated).toBe(
      false,
    )
    client.clear()
  })

  it('показывает отсутствие подключений как успешный ответ', async () => {
    server.use(
      http.get('/api/admin/companies/{id}', ({ params, response }) =>
        response(200).json(card(params.id)),
      ),
    )
    const data = await apiGet<ClientAccountDetail>(
      'http://localhost/api/admin/companies/empty',
    )
    expect(data.connections).toEqual([])
    expect(data.members).toEqual([])
  })

  it('сохраняет код 404 для отдельного состояния «Компания не найдена»', async () => {
    server.use(
      http.get('/api/admin/companies/{id}', ({ response }) =>
        response(404).json({
          status: 404,
          code: 'company_not_found',
          message: 'Компания не найдена.',
        }),
      ),
    )
    await expect(
      apiGet('http://localhost/api/admin/companies/missing'),
    ).rejects.toMatchObject({ status: 404, code: 'company_not_found' })
  })
})
