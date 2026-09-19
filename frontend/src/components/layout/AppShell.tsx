import { BarChart3, Boxes, Coffee, FileText, LayoutDashboard, LayoutGrid, LogOut, Menu, ReceiptText, Settings, ShoppingCart, Users, WalletCards, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../../features/auth/AuthProvider';

const navigation=[
  {label:'Dashboard',to:'/dashboard',icon:LayoutDashboard,permissions:[],section:'Overview'},
  {label:'POS',to:'/pos',icon:ShoppingCart,permissions:['orders.view','orders.create'],section:'Operations'},
  {label:'Cash Register',to:'/cash-register',icon:WalletCards,permissions:['cash_sessions.open','cash_sessions.close','payments.collect'],section:'Operations'},
  {label:'Bar Queue',to:'/bar',icon:Coffee,permissions:['orders.view'],section:'Operations'},
  {label:'Menu & Products',to:'/products',icon:ReceiptText,permissions:['products.view','products.manage'],section:'Management'},
  {label:'Inventory',to:'/inventory',icon:Boxes,permissions:['inventory.view','inventory.receive','inventory.transfer','inventory.adjust'],section:'Management'},
  {label:'Finance',to:'/finance',icon:BarChart3,permissions:['finance.view','expenses.view','expenses.create','expenses.approve'],section:'Management'},
  {label:'Invoices',to:'/invoices',icon:FileText,permissions:['invoices.view','invoices.issue'],section:'Management'},
  {label:'Venue Setup',to:'/venue-setup',icon:LayoutGrid,permissions:['venue.manage','cash_registers.manage'],section:'Management'},
  {label:'Staff',to:'/staff',icon:Users,permissions:['users.view','users.manage','roles.manage'],section:'Administration'},
  {label:'Settings',to:'/settings',icon:Settings,permissions:['business.settings.manage'],section:'Administration'},
] as const;

export function AppShell(){
  const navigate=useNavigate();
  const location=useLocation();
  const [mobileNavOpen,setMobileNavOpen]=useState(false);
  const {user,activeBusiness,activeLocation,selectBusiness,selectLocation,logout,canAny}=useAuth();
  const visibleNavigation=navigation.filter(item=>item.permissions.length===0||canAny([...item.permissions]));
  const canUseCashRegister=canAny(['cash_sessions.open','cash_sessions.close','payments.collect']);
  const sections=[...new Set(visibleNavigation.map(item=>item.section))];
  const activeNavigation=navigation.find(item=>location.pathname===item.to)??navigation[0];
  const ActiveIcon=activeNavigation.icon;

  useEffect(()=>{
    if(!mobileNavOpen)return;
    const previousOverflow=document.body.style.overflow;
    const closeOnEscape=(event:KeyboardEvent)=>{if(event.key==='Escape')setMobileNavOpen(false)};
    document.body.style.overflow='hidden';
    window.addEventListener('keydown',closeOnEscape);
    return ()=>{
      document.body.style.overflow=previousOverflow;
      window.removeEventListener('keydown',closeOnEscape);
    };
  },[mobileNavOpen]);

  return <div className="app-shell">
    <a className="skip-link" href="#workspace-content">Skip to workspace</a>
    {mobileNavOpen&&<button type="button" className="sidebar-scrim" aria-label="Close navigation" onClick={()=>setMobileNavOpen(false)}/>}
    <aside className={`sidebar ${mobileNavOpen?'mobile-open':''}`} aria-label="Workspace navigation">
      <div className="sidebar-brand-row">
        <div className="brand"><div className="brand-mark">H</div><div><strong>Hospitality</strong><span>Bar & Café OS</span></div></div>
        <button type="button" className="sidebar-close" aria-label="Close navigation" onClick={()=>setMobileNavOpen(false)}><X size={18}/></button>
      </div>

      <div className="mobile-workspace-switchers">
        <label><span>Business</span><select aria-label="Mobile active business" value={activeBusiness?.id??''} onChange={e=>selectBusiness(e.target.value)}>{user?.businesses.map(b=><option key={b.id} value={b.id}>{b.name}</option>)}</select></label>
        <label><span>Location</span><select aria-label="Mobile active location" value={activeLocation?.id??''} onChange={e=>selectLocation(e.target.value)}>{activeBusiness?.locations.map(l=><option key={l.id} value={l.id}>{l.name}</option>)}</select></label>
      </div>

      <nav className="nav-list" aria-label="Main navigation">
        {sections.map(section=><div className="nav-section" key={section}>
          <span className="nav-section-label">{section}</span>
          {visibleNavigation.filter(item=>item.section===section).map(({label,to,icon:Icon})=><NavLink key={to} to={to} title={label} onClick={()=>setMobileNavOpen(false)} className={({isActive})=>`nav-item ${isActive?'active':''}`}><Icon size={18}/><span>{label}</span></NavLink>)}
        </div>)}
      </nav>

      <div className="sidebar-footer">
        <div className="sidebar-context-label">Active workspace</div>
        <div className="location-chip"><span className="status-dot"/>{activeLocation?.name??'Choose location'}</div>
        <small>{activeBusiness?.name??'Operational workspace'}</small>
        <span className="sidebar-role">{activeBusiness?.role?.name??'Member'}</span>
      </div>
    </aside>

    <main className="main-content">
      <header className="topbar">
        <div className="mobile-topbar-brand">
          <button type="button" className="mobile-menu-button" aria-label="Open navigation" aria-expanded={mobileNavOpen} onClick={()=>setMobileNavOpen(true)}><Menu size={20}/></button>
          <div><strong>{activeNavigation.label}</strong><span>{activeLocation?.name??activeBusiness?.name??'Workspace'}</span></div>
        </div>

        <div className="topbar-context" aria-label="Current module">
          <span className="topbar-context-icon"><ActiveIcon size={16}/></span>
          <div><span>{activeNavigation.section}</span><strong>{activeNavigation.label}</strong></div>
        </div>

        <div className="workspace-switchers">
          <label><span>Business</span><select aria-label="Active business" value={activeBusiness?.id??''} onChange={e=>selectBusiness(e.target.value)}>{user?.businesses.map(b=><option key={b.id} value={b.id}>{b.name}</option>)}</select></label>
          <label><span>Location</span><select aria-label="Active location" value={activeLocation?.id??''} onChange={e=>selectLocation(e.target.value)}>{activeBusiness?.locations.map(l=><option key={l.id} value={l.id}>{l.name}</option>)}</select></label>
        </div>

        <div className="topbar-actions">
          {canUseCashRegister&&<button type="button" className="ghost-button topbar-cash-button" onClick={()=>navigate('/cash-register')}><WalletCards size={16}/> Cash register</button>}
          <div className="account-chip"><div className="avatar">{user?.name.split(' ').map(x=>x[0]).slice(0,2).join('').toUpperCase()}</div><div><strong>{user?.name}</strong><span>{activeBusiness?.role?.name??user?.email}</span></div></div>
          <button type="button" className="icon-button" title="Sign out" aria-label="Sign out" onClick={()=>logout()}><LogOut size={18}/></button>
        </div>
      </header>
      <div className="page-container" id="workspace-content"><Outlet/></div>
    </main>
  </div>;
}
