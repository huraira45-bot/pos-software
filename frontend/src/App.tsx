import { Navigate, Route, BrowserRouter, Routes } from 'react-router-dom';
import LoginPage from './pages/LoginPage';
import CheckoutPage from './pages/CheckoutPage';
import DashboardPage from './pages/DashboardPage';
import ProductsPage from './pages/ProductsPage';
import CustomersPage from './pages/CustomersPage';
import ReportsPage from './pages/ReportsPage';
import SalesHistoryPage from './pages/SalesHistoryPage';
import UsinSettingsPage from './pages/UsinSettingsPage';
import AppShell from './components/layout/AppShell';
import { useAuthStore } from './stores/authStore';

function RequireAuth({ children }: { children: React.ReactNode }) {
  const session = useAuthStore((s) => s.session);
  return session ? <>{children}</> : <Navigate to="/login" replace />;
}

/** Catch-all target: cashiers have no `pos.reports-view` permission and would
 *  just get a 403 from /api/dashboard/summary, so they land on Checkout instead. */
function DefaultRedirect() {
  const session = useAuthStore((s) => s.session);
  if (!session) return <Navigate to="/login" replace />;
  const canViewReports = session.user.permissions?.includes('pos.reports-view') ?? false;
  return <Navigate to={canViewReports ? '/dashboard' : '/checkout'} replace />;
}

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route
          element={
            <RequireAuth>
              <AppShell />
            </RequireAuth>
          }
        >
          <Route path="/dashboard" element={<DashboardPage />} />
          <Route path="/checkout" element={<CheckoutPage />} />
          <Route path="/products" element={<ProductsPage />} />
          <Route path="/customers" element={<CustomersPage />} />
          <Route path="/sales-history" element={<SalesHistoryPage />} />
          <Route path="/reports" element={<ReportsPage />} />
          <Route path="/reports/:reportKey" element={<ReportsPage />} />
          <Route path="/usin-settings" element={<UsinSettingsPage />} />
        </Route>
        <Route path="*" element={<DefaultRedirect />} />
      </Routes>
    </BrowserRouter>
  );
}

export default App;
