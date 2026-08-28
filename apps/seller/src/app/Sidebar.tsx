import {
  Calculator,
  TrendingDown,
  LogOut,
  Plug,
  Puzzle,
  Tag,
  TrendingUp,
} from 'lucide-react'
import { NavLink } from 'react-router'

import { Button } from '../../../../packages/ui/src'
import { useCurrentUser } from '../features/auth/model/useCurrentUser'
import { useLogout } from '../features/auth/model/useLogout'

// Раскладка навигации — эталон docs/design/ui-kit/v0.4.html, раздел 14:
// ширина 240, поле 16 сверху и 12 по бокам, шаг 4, элемент высотой 36
// с отступом 8 и радиусом 6, активный — accent-subtle с accent-hover
// и полужирным.
// no-underline — глобальное a:hover подчёркивает ссылки, а пункт меню
// в ките на наведении меняет только фон.
const ITEM =
  'flex h-9 items-center gap-3 rounded-md px-2 text-sm no-underline ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default'
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
  { to: 'unit-economics', label: 'Экономика', icon: Calculator },
  { to: 'costs', label: 'Себестоимость', icon: Tag },
  { to: 'prices', label: 'Цены и соинвест', icon: TrendingDown },
  { to: 'extension', label: 'Расширение', icon: Puzzle },
  { to: 'connections', label: 'Подключения', icon: Plug },
] as const

export function Sidebar() {
  const currentUser = useCurrentUser()
  const logout = useLogout()

  return (
    // overflow-y-auto — на низком окне и при зуме шесть пунктов и блок
    // выхода не помещаются; без своего скролла «Выйти» просто отрезана,
    // доскроллить до неё нечем — документ больше не прокручивается.
    <nav
      aria-label="Разделы компании"
      className="flex w-60 flex-col gap-1 overflow-y-auto border-r border-border-default bg-surface-raised px-3 py-4"
    >
      {/* Компании здесь больше нет: переключатель переехал в шапку,
          как в ките. Дублировать его в двух местах — верный способ
          получить два разных ответа на вопрос «в какой я компании». */}
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
