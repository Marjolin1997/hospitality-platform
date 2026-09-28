import { lazy, Suspense, type ReactNode } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { AppShell } from './components/layout/AppShell';
import { useAuth } from './features/auth/AuthProvider';
import { LoginPage } from './pages/auth/LoginPage';
import { InvitationAcceptPage } from './pages/auth/InvitationAcceptPage';
import { moduleAccess, type ModuleAccessKey } from './lib/moduleAccess';

const DashboardPage = lazy(() => import('./pages/dashboard/DashboardPage').then(module => ({ default: module.DashboardPage })));
const PosPage = lazy(() => import('./pages/pos/PosPage').then(module => ({ default: module.PosPage })));
const CashRegisterPage = lazy(() => import('./pages/cash-register/CashRegisterPage').then(module => ({ default: module.CashRegisterPage })));
const BarQueuePage = lazy(() => import('./pages/bar/BarQueuePage').then(module => ({ default: module.BarQueuePage })));
const InvoicePrintPage = lazy(() => import('./pages/invoices/InvoicePrintPage').then(module => ({ default: module.InvoicePrintPage })));
const CreditNotePrintPage = lazy(() => import('./pages/invoices/CreditNotePrintPage').then(module => ({ default: module.CreditNotePrintPage })));
const FiscalReceiptPage = lazy(() => import('./pages/invoices/FiscalReceiptPage').then(module => ({ default: module.FiscalReceiptPage })));

const ProductsPage = lazy(() => import('./pages/management/ManagementPages').then(module => ({ default: module.ProductsPage })));
const InventoryPage = lazy(() => import('./pages/management/InventoryPage').then(module => ({ default: module.InventoryPage })));
const FinancePage = lazy(() => import('./pages/management/ManagementPages').then(module => ({ default: module.FinancePage })));
const InvoicesPage = lazy(() => import('./pages/management/ManagementPages').then(module => ({ default: module.InvoicesPage })));
const StaffPage = lazy(() => import('./pages/management/ManagementPages').then(module => ({ default: module.StaffPage })));
const SettingsPage = lazy(() => import('./pages/management/ManagementPages').then(module => ({ default: module.SettingsPage })));
const VenueSetupPage = lazy(() => import('./pages/management/VenueSetupPage').then(module => ({ default: module.VenueSetupPage })));
const PurchasingPage = lazy(() => import('./pages/management/PurchasingPage').then(module => ({ default: module.PurchasingPage })));
const ReportsPage = lazy(() => import('./pages/management/ReportsPage').then(module => ({ default: module.ReportsPage })));

function RouteFallback() {
  return <div className="app-loading">Preparing your workspace…</div>;
}

function RequireModuleAccess({ access, children }: { access: ModuleAccessKey; children: ReactNode }) {
  const { activeBusiness, canAny } = useAuth();
  const permissions = moduleAccess[access];

  if (!activeBusiness) {
    return (
      <main className="management-page">
        <section className="panel management-state">
          <strong>No active workspace</strong>
          <span>Select or join an active business before opening this module.</span>
        </section>
      </main>
    );
  }

  if (permissions.length > 0 && !canAny([...permissions])) {
    return (
      <main className="management-page">
        <section className="panel management-state">
          <strong>Access required</strong>
          <span>Your current business role does not include access to this module.</span>
        </section>
      </main>
    );
  }

  return children;
}

export default function App() {
  const { user, loading, activeBusiness, activeLocation } = useAuth();

  if (loading) return <RouteFallback />;
  if (!user) return <Routes><Route path="/join/:token" element={<InvitationAcceptPage />} /><Route path="*" element={<LoginPage />} /></Routes>;

  return <Suspense fallback={<RouteFallback />}>
    <Routes>
      <Route path="/invoices/:invoiceId/print" element={<RequireModuleAccess access="invoices"><InvoicePrintPage /></RequireModuleAccess>} />
      <Route path="/invoices/:invoiceId/receipt/:paper" element={<RequireModuleAccess access="invoices"><FiscalReceiptPage /></RequireModuleAccess>} />
      <Route path="/invoice-credit-notes/:creditNoteId/print" element={<RequireModuleAccess access="invoices"><CreditNotePrintPage /></RequireModuleAccess>} />
      <Route path="/invoice-credit-notes/:creditNoteId/receipt/:paper" element={<RequireModuleAccess access="invoices"><FiscalReceiptPage /></RequireModuleAccess>} />
      <Route path="/join/:token" element={<InvitationAcceptPage />} />
      <Route element={<AppShell key={`${activeBusiness?.id??'business'}:${activeLocation?.id??'location'}`} />}>
        <Route index element={<Navigate to="/dashboard" replace />} />
        <Route path="/dashboard" element={<DashboardPage />} />
        <Route path="/pos" element={<RequireModuleAccess access="pos"><PosPage /></RequireModuleAccess>} />
        <Route path="/cash-register" element={<RequireModuleAccess access="cashRegister"><CashRegisterPage /></RequireModuleAccess>} />
        <Route path="/bar" element={<RequireModuleAccess access="barQueue"><BarQueuePage /></RequireModuleAccess>} />
        <Route path="/products" element={<RequireModuleAccess access="products"><ProductsPage /></RequireModuleAccess>} />
        <Route path="/inventory" element={<RequireModuleAccess access="inventory"><InventoryPage /></RequireModuleAccess>} />
        <Route path="/finance" element={<RequireModuleAccess access="finance"><FinancePage /></RequireModuleAccess>} />
        <Route path="/invoices" element={<RequireModuleAccess access="invoices"><InvoicesPage /></RequireModuleAccess>} />
        <Route path="/staff" element={<RequireModuleAccess access="staff"><StaffPage /></RequireModuleAccess>} />
        <Route path="/venue-setup" element={<RequireModuleAccess access="venueSetup"><VenueSetupPage /></RequireModuleAccess>} />
        <Route path="/purchasing" element={<RequireModuleAccess access="purchasing"><PurchasingPage /></RequireModuleAccess>} />
        <Route path="/reports" element={<RequireModuleAccess access="reports"><ReportsPage /></RequireModuleAccess>} />
        <Route path="/settings" element={<RequireModuleAccess access="settings"><SettingsPage /></RequireModuleAccess>} />
        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Route>
    </Routes>
  </Suspense>;
}
