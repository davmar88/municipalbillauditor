import { Route, Routes } from 'react-router'
import { PublicOnly, RequireAuth } from './auth/guards'
import { AppLayout, AuthLayout } from './components/Layout'
import { AccountPage } from './pages/AccountPage'
import { AddBillPage } from './pages/AddBillPage'
import { BillDetailPage } from './pages/BillDetailPage'
import { DashboardPage } from './pages/DashboardPage'
import { DisputeDetailPage } from './pages/DisputeDetailPage'
import { DisputesPage } from './pages/DisputesPage'
import { NotFoundPage } from './pages/NotFoundPage'
import { PropertyDetailPage } from './pages/PropertyDetailPage'
import { EditPropertyPage, NewPropertyPage } from './pages/PropertyFormPage'
import { SignInPage } from './pages/SignInPage'
import { SignUpPage } from './pages/SignUpPage'

export function AppRoutes() {
  return (
    <Routes>
      <Route element={<PublicOnly />}>
        <Route element={<AuthLayout />}>
          <Route path="/sign-in" element={<SignInPage />} />
          <Route path="/sign-up" element={<SignUpPage />} />
        </Route>
      </Route>
      <Route element={<RequireAuth />}>
        <Route element={<AppLayout />}>
          <Route index element={<DashboardPage />} />
          <Route path="/properties/new" element={<NewPropertyPage />} />
          <Route path="/properties/:propertyId" element={<PropertyDetailPage />} />
          <Route path="/properties/:propertyId/edit" element={<EditPropertyPage />} />
          <Route path="/properties/:propertyId/bills/new" element={<AddBillPage />} />
          <Route path="/bills/:billId" element={<BillDetailPage />} />
          <Route path="/disputes" element={<DisputesPage />} />
          <Route path="/disputes/:disputeId" element={<DisputeDetailPage />} />
          <Route path="/account" element={<AccountPage />} />
          <Route path="*" element={<NotFoundPage />} />
        </Route>
      </Route>
    </Routes>
  )
}
