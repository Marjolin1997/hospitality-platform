import { BarChart3, Boxes, Coffee, FileText, LayoutDashboard, ReceiptText, Settings, ShoppingCart, Users, WalletCards } from 'lucide-react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';

const navigation = [
  { label: 'Dashboard', to: '/dashboard', icon: LayoutDashboard },
  { label: 'POS', to: '/pos', icon: ShoppingCart },
  { label: 'Cash Register', to: '/cash-register', icon: WalletCards },
  { label: 'Bar Queue', to: '/bar', icon: Coffee },
  { label: 'Menu & Products', to: '/products', icon: ReceiptText },
  { label: 'Inventory', to: '/inventory', icon: Boxes },
  { label: 'Finance', to: '/finance', icon: BarChart3 },
  { label: 'Invoices', to: '/invoices', icon: FileText },
  { label: 'Staff', to: '/staff', icon: Users },
  { label: 'Settings', to: '/settings', icon: Settings },
];

export function AppShell() {
  const navigate = useNavigate();
  return <div className="app-shell"><aside className="sidebar"><div className="brand"><div className="brand-mark">H</div><div><strong>Hospitality</strong><span>Bar & Café OS</span></div></div><nav className="nav-list" aria-label="Main navigation">{navigation.map(({label,to,icon:Icon}) => <NavLink key={to} to={to} className={({isActive}) => `nav-item ${isActive?'active':''}`}><Icon size={19} strokeWidth={1.8}/><span>{label}</span></NavLink>)}</nav><div className="sidebar-footer"><div className="location-chip"><span className="status-dot"/> Tirana · Main Bar</div><small>Operational workspace</small></div></aside><main className="main-content"><header className="topbar"><div><span className="eyebrow">BUSINESS</span><strong>Demo Café</strong></div><div className="topbar-actions"><button className="ghost-button" onClick={() => navigate('/cash-register')}>Cash register</button><div className="avatar">MJ</div></div></header><div className="page-container"><Outlet/></div></main></div>;
}
