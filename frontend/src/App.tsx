import { Navigate, Route, Routes } from 'react-router-dom';
import { AppShell } from './components/layout/AppShell';
import { DashboardPage } from './pages/dashboard/DashboardPage';
import { PosPage } from './pages/pos/PosPage';
import { CashRegisterPage } from './pages/cash-register/CashRegisterPage';
import { BarQueuePage } from './pages/bar/BarQueuePage';

export default function App() {
  return <Routes><Route element={<AppShell/>}><Route index element={<Navigate to="/dashboard" replace/>}/><Route path="/dashboard" element={<DashboardPage/>}/><Route path="/pos" element={<PosPage/>}/><Route path="/cash-register" element={<CashRegisterPage/>}/><Route path="/bar" element={<BarQueuePage/>}/></Route></Routes>;
}
