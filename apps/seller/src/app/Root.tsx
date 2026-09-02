import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createBrowserRouter, Navigate, RouterProvider } from 'react-router'

import { CompanyListPage } from '../features/auth/ui/CompanyListPage'
import { ConfirmEmailPage } from '../features/auth/ui/ConfirmEmailPage'
import { BuyoutRatePage } from '../features/buyout-rate/ui/BuyoutRatePage'
import { EmailSentPage } from '../features/auth/ui/EmailSentPage'
import { LoginPage } from '../features/auth/ui/LoginPage'
import { OnboardingStartPage } from '../features/auth/ui/OnboardingStartPage'
import { ResendConfirmationPage } from '../features/auth/ui/ResendConfirmationPage'
import { ConnectionsPage } from '../features/connections/ui/ConnectionsPage'
import { ListingCostsPage } from '../features/costs/ui/ListingCostsPage'
import { PriceOverviewPage } from '../features/price-monitoring/ui/PriceOverviewPage'
import { ExtensionConnectPage } from '../features/extension/ui/ExtensionConnectPage'
import { SalesFactsPage } from '../features/ingestion/ui/SalesFactsPage'
import { SignUpPage } from '../features/auth/ui/SignUpPage'
import { UnitEconomicsPage } from '../features/unit-economics/ui/UnitEconomicsPage'
import { CompanyLayout } from './CompanyLayout'
import { RequireAuth } from './RequireAuth'

// retry: false — TanStack Query по умолчанию повторяет неудачный запрос
// трижды с задержкой; для 401/403 это не транзиентный сбой, а ответ,
// который не изменится, и повторы только откладывают редирект
// (RequireAuth, SalesFactsPage) на секунды.
const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: false } },
})

const router = createBrowserRouter([
  // Корень ведёт к выбору компании. Ping-экран, с которого начиналась
  // первая полоска насквозь, убран: продуктовой ценности он не имеет,
  // а занимал единственный адрес, который человек набирает руками.
  { path: '/', element: <Navigate to="/companies" replace /> },
  { path: '/login', element: <LoginPage /> },
  { path: '/sign-up', element: <SignUpPage /> },
  { path: '/sign-up/email-sent', element: <EmailSentPage /> },
  { path: '/resend-confirmation', element: <ResendConfirmationPage /> },
  { path: '/confirm-email', element: <ConfirmEmailPage /> },
  {
    path: '/onboarding',
    element: (
      <RequireAuth>
        <OnboardingStartPage />
      </RequireAuth>
    ),
  },
  {
    path: '/companies',
    element: (
      <RequireAuth>
        <CompanyListPage />
      </RequireAuth>
    ),
  },
  {
    // Оболочка компании: сайдбар и всё, что внутри. Адреса — про
    // предметную область, а не про наши модули: `sales`, не
    // `ingestion/sales-facts`. «Ingestion» — имя модуля бэкенда,
    // продавцу оно ничего не говорит.
    path: '/companies/:companyId',
    element: <CompanyLayout />,
    children: [
      { index: true, element: <Navigate to="sales" replace /> },
      { path: 'sales', element: <SalesFactsPage /> },
      { path: 'redemption', element: <BuyoutRatePage /> },
      { path: 'extension', element: <ExtensionConnectPage /> },
      { path: 'connections', element: <ConnectionsPage /> },
      { path: 'unit-economics', element: <UnitEconomicsPage /> },
      { path: 'costs', element: <ListingCostsPage /> },
      // Адрес про предметную область, а не про модуль (docs/structure.md):
      // продавцу «price-monitoring» ничего не говорит.
      { path: 'prices', element: <PriceOverviewPage /> },
    ],
  },
])

// Роутер и провайдеры (docs/structure.md, «app/»).
export function Root() {
  return (
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>
  )
}
