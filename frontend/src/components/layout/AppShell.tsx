import { BarChart3, Boxes, Coffee, FileText, LayoutDashboard, LogOut, ReceiptText, Settings, ShoppingCart, Users, WalletCards } from 'lucide-react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../../features/auth/AuthProvider';

const navigation=[
  {label:'Dashboard',to:'/dashboard',icon:LayoutDashboard,permissions:[]},
  {label:'POS',to:'/pos',icon:ShoppingCart,permissions:['orders.view','orders.create']},
  {label:'Cash Register',to:'/cash-register',icon:WalletCards,permissions:['cash_sessions.open','cash_sessions.close','payments.collect']},
  {label:'Bar Queue',to:'/bar',icon:Coffee,permissions:['orders.view']},
  {label:'Menu & Products',to:'/products',icon:ReceiptText,permissions:['products.view','products.manage']},
  {label:'Inventory',to:'/inventory',icon:Boxes,permissions:['inventory.view','inventory.receive','inventory.transfer','inventory.adjust']},
  {label:'Finance',to:'/finance',icon:BarChart3,permissions:['finance.view','expenses.view','expenses.create','expenses.approve']},
  {label:'Invoices',to:'/invoices',icon:FileText,permissions:['invoices.view','invoices.issue']},
  {label:'Staff',to:'/staff',icon:Users,permissions:['users.view','users.manage','roles.manage']},
  {label:'Settings',to:'/settings',icon:Settings,permissions:['business.settings.manage']},
];

export function AppShell(){
  const navigate=useNavigate();
  const {user,activeBusiness,activeLocation,selectBusiness,selectLocation,logout,canAny}=useAuth();
  const visibleNavigation=navigation.filter(item=>item.permissions.length===0||canAny(item.permissions));
  const canUseCashRegister=canAny(['cash_sessions.open','cash_sessions.close','payments.collect']);

  return <div className="app-shell">
    <aside className="sidebar">
      <div className="brand"><div className="brand-mark">H</div><div><strong>Hospitality</strong><span>Bar & Café OS</span></div></div>
      <nav className="nav-list" aria-label="Main navigation">{visibleNavigation.map(({label,to,icon:Icon})=><NavLink key={to} to={to} title={label} className={({isActive})=>`nav-item ${isActive?'active':''}`}><Icon size={19}/><span>{label}</span></NavLink>)}</nav>
      <div className="sidebar-footer"><div className="location-chip"><span className="status-dot"/>{activeLocation?.name??'Choose location'}</div><small>{activeBusiness?.role?.name??activeBusiness?.name??'Operational workspace'}</small></div>
    </aside>
    <main className="main-content">
      <header className="topbar">
        <div className="workspace-switchers">
          <label><span>Business</span><select aria-label="Active business" value={activeBusiness?.id??''} onChange={e=>selectBusiness(e.target.value)}>{user?.businesses.map(b=><option key={b.id} value={b.id}>{b.name}</option>)}</select></label>
          <label><span>Location</span><select aria-label="Active location" value={activeLocation?.id??''} onChange={e=>selectLocation(e.target.value)}>{activeBusiness?.locations.map(l=><option key={l.id} value={l.id}>{l.name}</option>)}</select></label>
        </div>
        <div className="topbar-actions">
          {canUseCashRegister&&<button className="ghost-button" onClick={()=>navigate('/cash-register')}><WalletCards size={16}/> Cash register</button>}
          <div className="account-chip"><div className="avatar">{user?.name.split(' ').map(x=>x[0]).slice(0,2).join('').toUpperCase()}</div><div><strong>{user?.name}</strong><span>{activeBusiness?.role?.name??user?.email}</span></div></div>
          <button className="icon-button" title="Sign out" aria-label="Sign out" onClick={()=>logout()}><LogOut size={18}/></button>
        </div>
      </header>
      <div className="page-container"><Outlet/></div>
    </main>
  </div>;
}
