import { useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'

import { Button, Card, Input } from '../../../../../../packages/ui/src'
import { ApiError } from '../../../api/ApiError'
import { createCompanyApiClient } from '../../../api/companyClient'
import {
  adsError,
  budgetInput,
  parseBudget,
  uncertainWrite,
  validCampaign,
  validInterval,
  validWindow,
} from '../lib/presentation'
import { adsKey } from '../model/queries'
import type { CreatePolicy, Policy, UpdatePolicy } from '../model/types'

interface Account {
  id: string
  label: string
  available: boolean
}
export function PolicyForm({
  companyId,
  blocked,
  policy,
  accounts,
  defaultAccount,
  onDirty,
  onPending,
  onClose,
  onSaved,
}: {
  companyId: string
  blocked: boolean
  policy: Policy | null
  accounts: Account[]
  defaultAccount: string
  onDirty: (dirty: boolean) => void
  onPending: (pending: boolean) => void
  onClose: () => void
  onSaved: () => void
}) {
  const [initial] = useState(() => ({
    accountId: policy?.accountId ?? defaultAccount,
    campaignId: policy?.campaignId ?? '',
    budget: policy === null ? '' : budgetInput(policy.dailyLimit.amountMinor),
    start: policy?.start ?? '09:00',
    stop: policy?.stop ?? '21:00',
    interval: String(policy?.pollSeconds ?? 600),
    enabled: policy?.enabled ?? true,
  }))
  const [form, setForm] = useState(initial)
  const [unknown, setUnknown] = useState(false)
  const locked = useRef(false)
  const alive = useRef(true)
  const queryClient = useQueryClient()
  const dirty = JSON.stringify(form) !== JSON.stringify(initial)
  useEffect(() => {
    onDirty(dirty)
  }, [dirty, onDirty])
  useEffect(() => {
    alive.current = true
    return () => {
      alive.current = false
      onDirty(false)
      onPending(false)
    }
  }, [onDirty, onPending])
  const amountMinor = parseBudget(form.budget)
  const pollSeconds = validInterval(form.interval)
  const accountAvailable = accounts.some(
    (a) => a.id === form.accountId && a.available,
  )
  const valid =
    amountMinor !== null &&
    pollSeconds !== null &&
    validWindow(form.start, form.stop) &&
    validCampaign(form.campaignId) &&
    (accountAvailable || (policy !== null && !form.enabled))
  const mutation = useMutation({
    mutationFn: async () => {
      if (!valid || amountMinor === null || pollSeconds === null)
        throw new Error('invalid_form')
      const input: Omit<UpdatePolicy, 'version'> = {
        dailyLimit: { amountMinor, currency: 'RUB' },
        start: form.start,
        stop: form.stop,
        pollSeconds,
        enabled: form.enabled,
      }
      const api = createCompanyApiClient(companyId)
      if (policy !== null)
        return api.put<Policy>(
          `/ozon-ads/policies/${encodeURIComponent(policy.id)}`,
          { ...input, version: policy.version } satisfies UpdatePolicy,
        )
      return api.post<Policy>('/ozon-ads/policies', {
        ...input,
        accountId: form.accountId,
        campaignId: form.campaignId,
      } satisfies CreatePolicy)
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: adsKey(companyId, 'policies'),
        }),
        queryClient.invalidateQueries({
          queryKey: adsKey(companyId, 'events'),
        }),
      ])
      if (alive.current) onSaved()
    },
    onError: async (error) => {
      if (alive.current && uncertainWrite(error)) setUnknown(true)
      await queryClient.invalidateQueries({
        queryKey: adsKey(companyId, 'policies'),
      })
    },
  })
  const conflict =
    mutation.error instanceof ApiError &&
    mutation.error.code === 'version_conflict'
  const set = (key: keyof typeof form, value: string | boolean) =>
    setForm((prev) => ({ ...prev, [key]: value }))
  return (
    <Card>
      <form
        className="flex flex-col gap-4"
        onSubmit={(event) => {
          event.preventDefault()
          if (blocked || !valid || locked.current || unknown || conflict) return
          locked.current = true
          onPending(true)
          void mutation
            .mutateAsync()
            .catch(() => undefined)
            .finally(() => {
              locked.current = false
              if (alive.current) onPending(false)
            })
        }}
      >
        <h2 className="text-lg font-semibold">
          {policy === null
            ? 'Новое наблюдение'
            : `Настройки кампании ${policy.campaignId}`}
        </h2>
        <p className="text-sm text-text-secondary">
          Стратегия «Контроль затрат» · режим наблюдения. Сохранение и
          увеличение лимита не запускают рекламу.
        </p>
        <fieldset
          disabled={mutation.isPending || blocked}
          className="flex flex-col gap-4"
        >
          <label className="flex flex-col gap-1 text-sm">
            Рекламное подключение
            <select
              className="rounded-md border border-border-default bg-surface-raised px-3 py-2"
              value={form.accountId}
              disabled={policy !== null}
              onChange={(event) => set('accountId', event.target.value)}
            >
              <option value="">Выберите подключение</option>
              {accounts.map((account) => (
                <option
                  key={account.id}
                  value={account.id}
                  disabled={!account.available}
                >
                  {account.label}
                  {account.available ? '' : ' — недоступно'}
                </option>
              ))}
            </select>
          </label>
          <Input
            label="ID кампании Ozon"
            value={form.campaignId}
            maxLength={19}
            disabled={policy !== null}
            onChange={(event) => set('campaignId', event.target.value)}
            hint="Существующая CPC-кампания. Скопируйте ID из рекламного кабинета Ozon."
          />
          <Input
            label="Суточный лимит наблюдения, ₽"
            inputMode="decimal"
            value={form.budget}
            onChange={(event) => set('budget', event.target.value)}
            hint="Положительная сумма, не более двух знаков после запятой. Бюджет Ozon не изменяется."
          />
          <div className="flex flex-wrap gap-4">
            <Input
              label="Начало, Europe/Moscow (UTC+3)"
              type="time"
              value={form.start}
              onChange={(event) => set('start', event.target.value)}
            />
            <Input
              label="Окончание, Europe/Moscow (UTC+3)"
              type="time"
              value={form.stop}
              onChange={(event) => set('stop', event.target.value)}
            />
          </div>
          <p className="text-sm text-text-muted">
            Ежедневное окно в пределах одних суток: начало раньше окончания.
          </p>
          <Input
            label="Интервал чтения Ozon, секунд"
            inputMode="numeric"
            value={form.interval}
            onChange={(event) => set('interval', event.target.value)}
            hint="От 600 секунд (10 минут) до 86400 секунд. Ограничения квот могут отложить чтение."
          />
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={form.enabled}
              onChange={(event) => set('enabled', event.target.checked)}
            />
            Наблюдение включено
          </label>
        </fieldset>
        {!valid && (
          <p role="status" className="text-sm text-text-secondary">
            Укажите доступное подключение, ID кампании, положительный лимит,
            корректное окно и интервал.
          </p>
        )}
        {mutation.isError && (
          <p role="alert" className="text-sm text-negative-text">
            {adsError(mutation.error)}
          </p>
        )}
        {unknown && (
          <p role="alert" className="text-sm text-warning-text">
            Результат сохранения неизвестен. Закройте форму и проверьте
            обновлённый список перед повтором.
          </p>
        )}
        <div className="flex flex-wrap gap-2">
          <Button
            type="submit"
            disabled={
              !valid || blocked || mutation.isPending || unknown || conflict
            }
          >
            {mutation.isPending ? 'Сохраняем…' : 'Сохранить наблюдение'}
          </Button>
          <Button
            type="button"
            variant="secondary"
            disabled={mutation.isPending}
            onClick={onClose}
          >
            Закрыть форму
          </Button>
        </div>
      </form>
    </Card>
  )
}
