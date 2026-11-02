import { Badge, Button, Card, Input } from '../../../../../../packages/ui/src'
import type { Project } from '../model/types'
import { useResearchForm } from '../model/useResearchForm'
import { CategoryPicker } from './CategoryPicker'
import { PeriodFields } from './PeriodFields'
import { ResearchError } from './ResearchError'

export function ResearchForm({
  companyId,
  project,
  mode,
  onCancel,
  blocked = false,
}: {
  companyId: string
  project?: Project
  mode: 'create' | 'copy' | 'add'
  onCancel: () => void
  blocked?: boolean
}) {
  const {
    category,
    setCategory,
    selection,
    setSelection,
    setIncludeCurrent,
    name,
    setName,
    comment,
    setComment,
    unknown,
    today,
    currentVisible,
    flag,
    valid,
    preview,
    canSave,
    mutation,
    checkList,
    submit,
  } = useResearchForm(companyId, mode, onCancel, project, blocked)
  return (
    <Card>
      <form
        className="flex flex-col gap-4"
        onSubmit={(event) => {
          event.preventDefault()
          submit()
        }}
      >
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-lg font-semibold">
            {mode === 'create'
              ? 'Новый проект'
              : mode === 'copy'
                ? 'Копировать проект'
                : 'Добавить периоды'}
          </h2>
          <Button
            type="button"
            variant="ghost"
            size="compact"
            onClick={onCancel}
          >
            Отмена
          </Button>
        </div>
        {mode !== 'add' && (
          <CategoryPicker
            companyId={companyId}
            selected={category}
            onSelect={setCategory}
          />
        )}
        {mode === 'add' && (
          <p className="text-sm text-text-secondary">
            Категория: {project?.categoryPath}. Периоды сохранятся в проекте;
            текущий запуск не изменится.
          </p>
        )}
        <PeriodFields
          selection={selection}
          preview={preview.data}
          today={today}
          onChange={setSelection}
          allowShortcuts={mode !== 'add'}
        />
        {currentVisible && (
          <label className="flex items-start gap-2 text-sm">
            <input
              type="checkbox"
              checked={flag}
              disabled={mode === 'add' && project?.includeCurrentMonth === true}
              onChange={(e) => {
                setIncludeCurrent(e.target.checked)
              }}
            />
            Включить неполный текущий месяц — с 1-го числа по вчера
          </label>
        )}
        <div className="flex flex-wrap gap-2">
          <Badge>FBO + FBS включены</Badge>
          <Badge>RUB</Badge>
        </div>
        {preview.isFetching && (
          <p role="status" className="text-sm text-text-secondary">
            Обновляем предпросмотр…
          </p>
        )}
        {valid && preview.isError && (
          <ResearchError
            error={preview.error}
            retry={() => {
              void preview.refetch()
            }}
          />
        )}
        {!valid && (
          <p role="status" className="text-sm text-text-secondary">
            Укажите начало и конец каждого периода. Будущие месяцы недоступны.
          </p>
        )}
        {valid && preview.isSuccess && !preview.isFetching && (
          <div
            className="rounded-md border border-border-default bg-surface-sunken p-4"
            aria-live="polite"
          >
            <p className="font-medium">
              {mode === 'add' ? 'В проекте будет' : 'Будет загружено'}{' '}
              {preview.data.monthCount} мес.: {preview.data.summary}
            </p>
            <p className="mt-1 text-sm text-text-secondary">
              {preview.data.cachedMonthCount === null
                ? 'Доступность кэша уточнится при загрузке.'
                : `Ориентировочно из кэша: ${preview.data.cachedMonthCount} мес. По готовым данным проектов этой компании; итог уточнится при загрузке.`}
            </p>
            <p className="mt-1 text-xs text-text-muted">
              {preview.data.months.some((m) => m.partial)
                ? 'Полные месяцы и неполный текущий'
                : 'Календарные месяцы целиком'}{' '}
              · {preview.data.timezone} · до 24 уникальных месяцев
            </p>
          </div>
        )}
        {mode !== 'add' && (
          <>
            <Input
              label="Название проекта"
              value={name}
              placeholder={
                preview.data?.suggestedName ??
                'Название подставится из категории и периодов'
              }
              maxLength={200}
              onChange={(e) => {
                setName(e.target.value)
              }}
              hint="Оставьте пустым, чтобы использовать название из предпросмотра."
            />
            <label className="flex flex-col gap-1 text-xs font-medium text-text-secondary">
              Комментарий
              <textarea
                className="min-h-24 rounded-md border border-border-default bg-surface-raised px-3 py-2 text-sm text-text-primary focus:outline-2 focus:outline-border-focus"
                maxLength={2000}
                value={comment}
                onChange={(e) => {
                  setComment(e.target.value)
                }}
                placeholder="Зачем исследуем эту категорию"
              />
            </label>
          </>
        )}
        {mutation.isError && <ResearchError error={mutation.error} />}
        {unknown && (
          <p role="alert" className="text-sm text-warning-text">
            Результат сохранения не подтверждён. Проверьте список проектов перед
            повторным созданием.
          </p>
        )}
        <div className="flex flex-wrap gap-2">
          <Button
            type="submit"
            variant="primary"
            disabled={!canSave || mutation.isPending}
          >
            {mutation.isPending
              ? 'Сохраняем…'
              : mode === 'create'
                ? 'Создать проект'
                : mode === 'copy'
                  ? 'Создать копию'
                  : 'Сохранить периоды'}
          </Button>
          {unknown && (
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                void checkList()
              }}
            >
              Открыть список проектов
            </Button>
          )}
        </div>
      </form>
    </Card>
  )
}
