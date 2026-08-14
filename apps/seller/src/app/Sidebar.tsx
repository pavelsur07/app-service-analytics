import { LogOut, Plug, Puzzle, TrendingUp } from 'lucide-react'
import { NavLink } from 'react-router'

import { Button } from '../../../../packages/ui/src'
import { useCurrentUser } from '../features/auth/model/useCurrentUser'
import { useLogout } from '../features/auth/model/useLogout'

// Раскладка навигации — эталон docs/design/ui-kit/v0.2.html, раздел 08:
// ширина 240, элемент высотой 36 с отступом 8 и радиусом 6, активный —
// accent-subtle с accent-hover и полужирным.
const ITEM =
  'flex h-9 items-center gap-3 rounded-md px-2 text-sm ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent'
const ITEM_ACTIVE = 'bg-accent-subtle font-semibold text-accent-hover'
const ITEM_IDLE = 'text-text-secondary hover:bg-surface-hover'

/**
 * Пункты — только то, что открывается. Раздел-заглушка обещает
 * функциональность, которой нет, и объясняться за неё придётся перед
 * клиентом. Появится юнит-экономика — появится и пункт.
 *
 * Маркетплейс пунктом не становится: «Продажи Ozon» и «Продажи WB»
 * умножили бы меню на число площадок. Площадка — фильтр внутри экрана.
 */
const ITEMS = [
  { to: 'sales', label: 'Продажи', icon: TrendingUp },
  { to: 'extension', label: 'Расширение', icon: Puzzle },
  { to: 'connections', label: 'Подключения', icon: Plug },
] as const

export function Sidebar({ companyId }: { companyId: string }) {
  const currentUser = useCurrentUser()
  const logout = useLogout()

  const company = currentUser.data?.companies.find(
    (candidate) => candidate.id === companyId,
  )

  return (
    <nav
      aria-label="Разделы компании"
      className="flex h-full w-60 flex-col gap-1 border-r border-border-default bg-surface-raised p-3"
    >
      {/* Название компании — ссылка на выбор: у первого клиента компания
          одна, и выпадающий список был бы списком из одного пункта.
          Экран выбора уже существует, ведём на него. */}
      <NavLink
        to="/companies"
        className="mb-2 truncate rounded-md px-2 py-1 text-sm font-semibold text-text-primary hover:bg-surface-hover"
      >
        {company?.name ?? 'Компания'}
      </NavLink>

      {ITEMS.map(({ to, label, icon: Icon }) => (
        <NavLink
          key={to}
          to={to}
          className={({ isActive }) =>
            `${ITEM} ${isActive ? ITEM_ACTIVE : ITEM_IDLE}`
          }
        >
          <Icon aria-hidden="true" size={16} />
          {label}
        </NavLink>
      ))}

      {/* Выход внизу и на каждом экране. Раньше он жил только на выборе
          компании — то есть после входа в компанию был недоступен. */}
      <div className="mt-auto flex flex-col gap-2 border-t border-border-subtle pt-3">
        <span className="truncate px-2 text-xs text-text-muted">
          {currentUser.data?.email}
        </span>
        <Button
          variant="ghost"
          size="compact"
          onClick={() => logout.mutate()}
          disabled={logout.isPending}
        >
          <LogOut aria-hidden="true" size={14} />
          Выйти
        </Button>
      </div>
    </nav>
  )
}
