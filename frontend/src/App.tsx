import { Navigate, Route, Routes } from 'react-router-dom';
import { AppShell } from './components/layout/AppShell';
import { useAuth } from './features/auth/AuthProvider';
import { LoginPage } from './pages/auth/LoginPage';
import { BarQueuePage } from './pages/bar/BarQueuePage';
import { CashRegisterPage } from './pages/cash-register/CashRegisterPage';
import { DashboardPage } from './pages/dashboard/DashboardPage';
import { PosPage } from './pages/pos/PosPage';

export default function App(){const {user,loading}=useAuth();if(loading)return <div className="app-loading">Preparing your workspace…</div>;if(!user)return <Routes><Route path="*" element={<LoginPage/>}/></Routes>;return <Routes><Route element={<AppShell/>}><Route index element={<Navigate to="/dashboard" replace/>}/><Route path="/dashboard" element={<DashboardPage/>}/><Route path="/pos" element={<PosPage/>}/><Route path="/cash-register" element={<CashRegisterPage/>}/><Route path="/bar" element={<BarQueuePage/>}/></Route></Routes>;}
