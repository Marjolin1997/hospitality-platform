import { useIsFetching, useIsMutating } from '@tanstack/react-query';
import { BarChart3, Boxes, Coffee, FileText, LayoutDashboard, LayoutGrid, LogOut, Menu, ReceiptText, Search, Settings, ShoppingCart, Truck, Users, WalletCards, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { WorkspaceErrorBoundary } from '../errors/WorkspaceErrorBoundary';
import { WorkspaceCommandPalette } from './WorkspaceCommandPalette';
import { useAuth } from '../../features/auth/AuthProvider';
import { moduleAccess } from '../../lib/moduleAccess';

const navigation=[
  {label:'Dashboard',to:'/dashboard',icon:LayoutDashboard,permissions:moduleAccess.dashboard,section:'Overview',keywords:['home','overview','kpi']},
  {label:'POS',to:'/pos',icon:ShoppingCart,permissions:moduleAccess.pos,section:'Operations',keywords:['order','sale','table','checkout']},
  {label:'Cash Register',to:'/cash-register',icon:WalletCards,permissions:moduleAccess.cashRegister,section:'Operations',keywords:['cash','drawer','shift','till']},
  {label:'Bar Queue',to:'/bar',icon:Coffee,permissions:moduleAccess.barQueue,section:'Operations',keywords:['preparation','kitchen','ready','served']},
  {label:'Menu & Products',to:'/products',icon:ReceiptText,permissions:moduleAccess.products,section:'Management',keywords:['catalog','category','price','sku']},
  {label:'Inventory',to:'/inventory',icon:Boxes,permissions:moduleAccess.inventory,section:'Management',keywords:['stock','count','transfer','warehouse']},
  {label:'Purchasing',to:'/purchasing',icon:Truck,permissions:moduleAccess.purchasing,section:'Management',keywords:['supplier','purchase order','po','receive','goods receipt']},
  {label:'Finance',to:'/finance',icon:BarChart3,permissions:moduleAccess.finance,section:'Management',keywords:['expense','sales','refund','net']},
  {label:'Reports',to:'/reports',icon:BarChart3,permissions:moduleAccess.reports,section:'Management',keywords:['analytics','report','performance','export']},
  {label:'Invoices',to:'/invoices',icon:FileText,permissions:moduleAccess.invoices,section:'Management',keywords:['invoice','receipt','fiscal','credit note','qr']},
  {label:'Venue Setup',to:'/venue-setup',icon:LayoutGrid,permissions:moduleAccess.venueSetup,section:'Management',keywords:['area','table','cash register','layout']},
  {label:'Staff',to:'/staff',icon:Users,permissions:moduleAccess.staff,section:'Administration',keywords:['user','role','permission','invite','access']},
  {label:'Settings',to:'/settings',icon:Settings,permissions:moduleAccess.settings,section:'Administration',keywords:['business','location','fiscalization','profile']},
] as const;

export function AppShell(){
  const navigate=useNavigate();
  const location=useLocation();
  const activeFetches=useIsFetching();
  const activeMutations=useIsMutating();
  const workspaceBusy=activeFetches+activeMutations>0;
  const [mobileNavOpen,setMobileNavOpen]=useState(false);
  const [commandOpen,setCommandOpen]=useState(false);
  const [formDialogOpen,setFormDialogOpen]=useState(false);
  const {user,activeBusiness,activeLocation,selectBusiness,selectLocation,logout,canAny}=useAuth();
  const visibleNavigation=navigation.filter(item=>item.permissions.length===0||canAny([...item.permissions]));
  const canUseCashRegister=canAny(['cash_sessions.view','cash_sessions.open','cash_sessions.close','cash_movements.create','payments.collect']);
  const sections=[...new Set(visibleNavigation.map(item=>item.section))];
  const activeNavigation=navigation.find(item=>location.pathname===item.to)??navigation[0];
  const ActiveIcon=activeNavigation.icon;

  useEffect(()=>{
    const handleWorkspaceKeyboard=(event:KeyboardEvent)=>{
      if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='k'){
        event.preventDefault();
        if(!formDialogOpen)setCommandOpen(true);
        return;
      }

      if(event.key==='Tab'&&!commandOpen){
        const dialogs=Array.from(document.querySelectorAll<HTMLElement>('.modal-backdrop [role="dialog"]'));
        const dialog=dialogs.at(-1);
        if(dialog){
          const focusable=Array.from(dialog.querySelectorAll<HTMLElement>(
            'button:not(:disabled), [href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])',
          )).filter(element=>element.offsetParent!==null);
          if(focusable.length>0){
            const first=focusable[0];
            const last=focusable[focusable.length-1];
            const active=document.activeElement as HTMLElement|null;
            if(event.shiftKey&&(active===first||!dialog.contains(active))){
              event.preventDefault();
              last.focus();
              return;
            }
            if(!event.shiftKey&&(active===last||!dialog.contains(active))){
              event.preventDefault();
              first.focus();
              return;
            }
          }
        }
      }

      const target=event.target as HTMLElement|null;
      const isTyping=Boolean(target?.closest('input, textarea, select, [contenteditable="true"]'));

      if(event.key==='/'&&!event.ctrlKey&&!event.metaKey&&!event.altKey&&!isTyping&&!commandOpen){
        const pageSearch=document.querySelector<HTMLInputElement>('#workspace-content .search-box input:not(:disabled)');
        if(pageSearch){
          event.preventDefault();
          pageSearch.focus();
          pageSearch.select();
          return;
        }
      }

      if(event.key!=='Escape')return;

      if(commandOpen){
        event.preventDefault();
        setCommandOpen(false);
        return;
      }

      const modalCloseButtons=Array.from(
        document.querySelectorAll<HTMLButtonElement>('.modal-backdrop button[aria-label*="Close"]'),
      ).filter(button=>!button.disabled);

      const closeButton=modalCloseButtons.at(-1);
      if(closeButton){
        event.preventDefault();
        closeButton.click();
      }
    };

    const protectFormBackdrop=(event:MouseEvent)=>{
      const target=event.target;
      if(!(target instanceof HTMLElement))return;
      if(!target.classList.contains('modal-backdrop'))return;
      if(!target.querySelector('form'))return;
      event.stopPropagation();
    };

    window.addEventListener('keydown',handleWorkspaceKeyboard);
    document.addEventListener('mousedown',protectFormBackdrop,true);
    return ()=>{
      window.removeEventListener('keydown',handleWorkspaceKeyboard);
      document.removeEventListener('mousedown',protectFormBackdrop,true);
    };
  },[commandOpen,formDialogOpen]);

  useEffect(()=>{
    let locked=false;
    let previousOverflow='';

    const syncModalScrollLock=()=>{
      const hasModal=Boolean(document.querySelector('.modal-backdrop'));
      const hasFormModal=Boolean(document.querySelector('.modal-backdrop form'));
      setFormDialogOpen(hasFormModal);
      if(hasModal&&!locked){
        previousOverflow=document.body.style.overflow;
        document.body.style.overflow='hidden';
        locked=true;
        return;
      }
      if(!hasModal&&locked){
        document.body.style.overflow=previousOverflow;
        locked=false;
      }
    };

    const observer=new MutationObserver(syncModalScrollLock);
    observer.observe(document.body,{childList:true,subtree:true});
    syncModalScrollLock();

    return ()=>{
      observer.disconnect();
      if(locked)document.body.style.overflow=previousOverflow;
    };
  },[]);

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
        <label><span>Business</span><select aria-label="Mobile active business" value={activeBusiness?.id??''} disabled={formDialogOpen} title={formDialogOpen?'Finish or cancel the open form before switching business.':undefined} onChange={e=>selectBusiness(e.target.value)}>{user?.businesses.map(b=><option key={b.id} value={b.id}>{b.name}</option>)}</select></label>
        <label><span>Location</span><select aria-label="Mobile active location" value={activeLocation?.id??''} disabled={formDialogOpen} title={formDialogOpen?'Finish or cancel the open form before switching location.':undefined} onChange={e=>selectLocation(e.target.value)}>{activeBusiness?.locations.map(l=><option key={l.id} value={l.id}>{l.name}</option>)}</select></label>
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
      <div className={`workspace-activity-bar ${workspaceBusy?'active':''}`} aria-hidden="true"><span/></div>
      <span className="sr-only" role="status" aria-live="polite">{workspaceBusy?'Workspace updating':'Workspace up to date'}</span>
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
          <label><span>Business</span><select aria-label="Active business" value={activeBusiness?.id??''} disabled={formDialogOpen} title={formDialogOpen?'Finish or cancel the open form before switching business.':undefined} onChange={e=>selectBusiness(e.target.value)}>{user?.businesses.map(b=><option key={b.id} value={b.id}>{b.name}</option>)}</select></label>
          <label><span>Location</span><select aria-label="Active location" value={activeLocation?.id??''} disabled={formDialogOpen} title={formDialogOpen?'Finish or cancel the open form before switching location.':undefined} onChange={e=>selectLocation(e.target.value)}>{activeBusiness?.locations.map(l=><option key={l.id} value={l.id}>{l.name}</option>)}</select></label>
        </div>

        <div className="topbar-actions">
          <button type="button" className="ghost-button topbar-search-button" disabled={formDialogOpen} title={formDialogOpen?'Finish or cancel the open form before navigating away.':'Search workspace'} onClick={()=>setCommandOpen(true)} aria-label="Search workspace">
            <Search size={16}/>
            <span>Search</span>
            <kbd>Ctrl K</kbd>
          </button>
          {canUseCashRegister&&<button type="button" className="ghost-button topbar-cash-button" onClick={()=>navigate('/cash-register')}><WalletCards size={16}/> Cash register</button>}
          <div className="account-chip"><div className="avatar">{user?.name.split(' ').map(x=>x[0]).slice(0,2).join('').toUpperCase()}</div><div><strong>{user?.name}</strong><span>{activeBusiness?.role?.name??user?.email}</span></div></div>
          <button type="button" className="icon-button" title="Sign out" aria-label="Sign out" onClick={()=>logout()}><LogOut size={18}/></button>
        </div>
      </header>
      <div className="page-container" id="workspace-content">
        <WorkspaceErrorBoundary
          key={`${activeBusiness?.id??'business'}:${activeLocation?.id??'location'}:${location.pathname}`}
          onDashboard={()=>navigate('/dashboard')}
        >
          <Outlet/>
        </WorkspaceErrorBoundary>
      </div>
    </main>
    <WorkspaceCommandPalette
      open={commandOpen}
      items={visibleNavigation.map(item=>({
        label:item.label,
        section:item.section,
        to:item.to,
        icon:item.icon,
        keywords:[...item.keywords],
      }))}
      activePath={location.pathname}
      onClose={()=>setCommandOpen(false)}
      onNavigate={to=>navigate(to)}
    />
  </div>;
}
