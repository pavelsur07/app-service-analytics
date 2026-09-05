import { useState } from 'react'
import { CircleX, Link2 } from 'lucide-react'

import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import { currentUtcMonth } from '../model/month'
import { useLinks } from '../model/useLinks'
import { CreateLinkForm } from './CreateLinkForm'
import { EditLinkForm } from './EditLinkForm'
import { LinksTable } from './LinksTable'
import { MonthlyClicksTable } from './MonthlyClicksTable'

export function LinksPage() {
  const [page, setPage] = useState(1)
  const [creatingLink, setCreatingLink] = useState(false)
  const [selectedLinkId, setSelectedLinkId] = useState<string | null>(null)
  const [editingLinkId, setEditingLinkId] = useState<string | null>(null)
  const [month, setMonth] = useState(currentUtcMonth)
  const links = useLinks(page)

  const items = links.status === 'success' ? links.data.items : []
  const selectedId =
    selectedLinkId !== null && items.some((link) => link.id === selectedLinkId)
      ? selectedLinkId
      : (items[0]?.id ?? selectedLinkId)
  const editingLink = items.find((link) => link.id === editingLinkId)

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-semibold">Ссылки</h1>
          <p className="mt-1 text-sm text-text-muted">
            Короткие адреса для рассылок и переходы по дням.
          </p>
        </div>
        <Button
          onClick={() => {
            setCreatingLink(true)
          }}
          type="button"
        >
          Новая ссылка
        </Button>
      </header>

      {creatingLink && (
        <CreateLinkForm
          onCancel={() => {
            setCreatingLink(false)
          }}
          onCreated={(linkId) => {
            setPage(1)
            setSelectedLinkId(linkId)
            setCreatingLink(false)
          }}
        />
      )}
      {editingLink !== undefined && (
        <EditLinkForm
          key={editingLink.id}
          link={editingLink}
          onCancel={() => {
            setEditingLinkId(null)
          }}
          onSaved={() => {
            setEditingLinkId(null)
          }}
        />
      )}

      <Card>
        <h2 className="mb-4 text-lg font-semibold">Короткие ссылки</h2>

        {links.status === 'pending' && (
          <div className="h-28 animate-pulse rounded bg-border-subtle" />
        )}

        {links.status === 'error' && (
          <StatusPanel
            action={
              <Button
                onClick={() => {
                  void links.refetch()
                }}
                size="compact"
                type="button"
                variant="secondary"
              >
                Повторить
              </Button>
            }
            description={
              links.error instanceof Error
                ? links.error.message
                : 'Попробуйте обновить страницу.'
            }
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Не удалось загрузить ссылки"
            tone="negative"
          />
        )}

        {links.status === 'success' && links.data.items.length === 0 && (
          <StatusPanel
            description="Нажмите «Новая ссылка», чтобы создать первую ссылку."
            icon={<Link2 aria-hidden="true" size={20} />}
            title="Ссылок пока нет"
          />
        )}

        {links.status === 'success' && links.data.items.length > 0 && (
          <>
            <LinksTable
              links={links.data.items}
              onEdit={setEditingLinkId}
              onSelect={setSelectedLinkId}
              selectedLinkId={selectedId}
            />

            {links.data.pages > 1 && (
              <div className="mt-4 flex items-center gap-3 text-sm">
                <Button
                  disabled={page <= 1}
                  onClick={() => {
                    setPage((current) => current - 1)
                    setSelectedLinkId(null)
                    setEditingLinkId(null)
                  }}
                  size="compact"
                  type="button"
                  variant="secondary"
                >
                  Назад
                </Button>
                <span className="text-text-muted">
                  {links.data.page} из {links.data.pages}
                </span>
                <Button
                  disabled={page >= links.data.pages}
                  onClick={() => {
                    setPage((current) => current + 1)
                    setSelectedLinkId(null)
                    setEditingLinkId(null)
                  }}
                  size="compact"
                  type="button"
                  variant="secondary"
                >
                  Вперёд
                </Button>
              </div>
            )}
          </>
        )}
      </Card>

      <MonthlyClicksTable
        linkId={selectedId}
        month={month}
        onMonthChange={setMonth}
      />
    </div>
  )
}
