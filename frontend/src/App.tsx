import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { AppShell } from './components/layout/AppShell';
import { useAuth } from './features/auth/AuthProvider';
import { LoginPage } from './pages/auth/LoginPage';
import { InvitationAcceptPage } from './pages/auth/InvitationAcceptPage';

const DashboardPage = lazy(() => import('./pages/dashboard/DashboardPage').then(module => ({ default: module.DashboardPage })));
const PosPage = lazy(() => import('./pages/pos/PosPage').then(module => ({ default: module.PosPage })));
const CashRegisterPage = lazy(() => import('./pages/cash-register/CashRegisterPage').then(module => ({ default: module.CashRegisterPage })));
const BarQueuePage = lazy(() => import('./pages/bar/BarQueuePage').then(module => ({ default: module.BarQueuePage })));
const InvoicePrintPage = lazy(() => import('./pages/invoices/InvoicePrintPage').then(module => ({ default: module.InvoicePrintPage })));
const CreditNotePrintPage = lazy(() => import('./pages/invoices/CreditNotePrintPage').then(module => ({ default: module.CreditNotePrintPage })));
const FiscalReceiptPage = lazy(() => import('./pages/invoices/FiscalReceiptPage').then(module => ({ default: module.FiscalReceiptPage })));

const ProductsPage = lazy(() => import('./pages/management/ManagementPages').then(module => ({ default: module.ProductsPage })));
const InventoryPage = lazy(() => import('./pages/management/ManagementPages').then(module => ({ default: module.InventoryPage })));
const FinancePage = lazy(() => import('./pages/management/ManagementPages').then(module => ({ default: module.FinancePage })));
const InvoicesPage = lazy(() => import('./pages/management/ManagementPages').then(module => ({ default: module.InvoicesPage })));
const StaffPage = lazy(() => import('./pages/management/ManagementPages').then(module => ({ default: module.StaffPage })));
const SettingsPage = lazy(() => import('./pages/management/ManagementPages').then(module => ({ default: module.SettingsPage })));

function RouteFallback() {
  return <div className="app-loading">Preparing your workspace…</div>;
}

export default function App() {
  const { user, loading, activeBusiness, activeLocation } = useAuth();

  if (loading) return <RouteFallback />;
  if (!user) return <Routes><Route path="/join/:token" element={<InvitationAcceptPage />} /><Route path="*" element={<LoginPage />} /></Routes>;

  return <Suspense fallback={<RouteFallback />}>
    <Routes>
      <Route path="/invoices/:invoiceId/print" element={<InvoicePrintPage />} />
      <Route path="/invoices/:invoiceId/receipt/:paper" element={<FiscalReceiptPage />} />
      <Route path="/invoice-credit-notes/:creditNoteId/print" element={<CreditNotePrintPage />} />
      <Route path="/invoice-credit-notes/:creditNoteId/receipt/:paper" element={<FiscalReceiptPage />} />
      <Route path="/join/:token" element={<InvitationAcceptPage />} />
      <Route element={<AppShell key={`${activeBusiness?.id??'business'}:${activeLocation?.id??'location'}`} />}>
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
    </Routes>
  </Suspense>;
}
