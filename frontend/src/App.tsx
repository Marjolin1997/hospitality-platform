import { Navigate, Route, Routes } from 'react-router-dom';
import { AppShell } from './components/layout/AppShell';
import { useAuth } from './features/auth/AuthProvider';
import { LoginPage } from './pages/auth/LoginPage';
import { BarQueuePage } from './pages/bar/BarQueuePage';
import { CashRegisterPage } from './pages/cash-register/CashRegisterPage';
import { DashboardPage } from './pages/dashboard/DashboardPage';
import { FinancePage, InventoryPage, InvoicesPage, ProductsPage, SettingsPage, StaffPage } from './pages/management/ManagementPages';
import { PosPage } from './pages/pos/PosPage';
import { InvoicePrintPage } from './pages/invoices/InvoicePrintPage';

export default function App() {
  const { user, loading } = useAuth();
  if (loading) return <div className="app-loading">Preparing your workspace…</div>;
  if (!user) return <Routes><Route path="*" element={<LoginPage />} /></Routes>;

  return <Routes>
    <Route path="/invoices/:invoiceId/print" element={<InvoicePrintPage />} />
    <Route element={<AppShell />}>
      <Route index element={<Navigate to="/dashboard" replace />} />
      <Route path="/dashboard" element={<DashboardPage />} />
      <Route path="/pos" element={<PosPage />} />
      <Route path="/cash-register" element={<CashRegisterPage />} />
      <Route path="/bar" element={<BarQueuePage />} />
      <Route path="/products" element={<ProductsPage />} />
      <Route path="/inventory" element={<InventoryPage />} />
      <Route path="/finance" element={<FinancePage />} />
      <Route path="/invoices" element={<InvoicesPage />} />
      <Route path="/staff" element={<StaffPage />} />
      <Route path="/settings" element={<SettingsPage />} />
      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Route>
  </Routes>;
}
