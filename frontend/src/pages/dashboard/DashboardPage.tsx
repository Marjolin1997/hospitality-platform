import { useQuery } from '@tanstack/react-query';
import { ArrowUpRight, Banknote, Boxes, CircleAlert, Clock3, Coffee, RefreshCw, ShoppingBag, WalletCards } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../../features/auth/AuthProvider';
import { api } from '../../lib/api';

type DashboardOrder={id:string;number:string;location_id:string;venue_table_id:string|null;type:string;status:string;grand_total:string;currency:string;opened_at:string};
type DashboardStock={id:string;name:string;quantity_on_hand:string;reorder_level:string};
type DashboardQueue={id:string;preparation_status:string;sent_at:string|null};
type DashboardVenue={id:string;name:string;tables:Array<{id:string;name:string;is_active:boolean}>};
type DashboardCashSession={id:string};
type DashboardFinance={sales:string;gross_sales:string;refunds:string;expenses:string;net:string};

const money=(value:string|number,currency:string)=>new Intl.NumberFormat(undefined,{style:'currency',currency,maximumFractionDigits:0}).format(Number(value||0));
const greeting=()=>{const hour=new Date().getHours();return hour<12?'Good morning':hour<18?'Good afternoon':'Good evening'};

export function DashboardPage() {
  const navigate=useNavigate();
  const {activeBusiness,activeLocation,can}=useAuth();
  const businessId=activeBusiness?.id;
  const locationId=activeLocation?.id;
  const currency=activeBusiness?.currency??'EUR';

  const orders=useQuery({
    queryKey:['dashboard','orders',businessId],
    enabled:Boolean(businessId)&&can('orders.view'),
    queryFn:()=>api.get<{data:DashboardOrder[]}>('/orders',{params:{per_page:100}}).then(r=>r.data.data),
    refetchInterval:15000,
  });
  const inventory=useQuery({
    queryKey:['dashboard','inventory',businessId,locationId],
    enabled:Boolean(locationId)&&can('inventory.view'),
    queryFn:()=>api.get<{data:DashboardStock[]}>('/inventory',{params:{location_id:locationId}}).then(r=>r.data.data),
    refetchInterval:30000,
  });
  const queue=useQuery({
    queryKey:['dashboard','queue',businessId,locationId],
    enabled:Boolean(locationId)&&can('orders.view'),
    queryFn:()=>api.get<{data:DashboardQueue[]}>('/bar-queue',{params:{location_id:locationId}}).then(r=>r.data.data),
    refetchInterval:5000,
  });
  const venue=useQuery({
    queryKey:['dashboard','venue',businessId,locationId],
    enabled:Boolean(locationId)&&can('orders.view'),
    queryFn:()=>api.get<{data:DashboardVenue[]}>('/venue',{params:{location_id:locationId}}).then(r=>r.data.data),
    staleTime:60000,
  });
  const finance=useQuery({
    queryKey:['dashboard','finance',businessId],
    enabled:Boolean(businessId)&&can('finance.view'),
    queryFn:()=>api.get<{data:DashboardFinance}>('/finance/overview').then(r=>r.data.data),
    refetchInterval:30000,
  });
  const cashSessions=useQuery({
    queryKey:['dashboard','cash-sessions',businessId,locationId],
    enabled:Boolean(locationId)&&can('payments.collect'),
    queryFn:()=>api.get<{data:DashboardCashSession[]}>('/cash-sessions/open',{params:{location_id:locationId}}).then(r=>r.data.data),
    refetchInterval:15000,
  });

  const locationOrders=(orders.data??[]).filter(order=>order.location_id===locationId&&['open','payment_due'].includes(order.status));
  const lowStock=(inventory.data??[]).filter(item=>Number(item.quantity_on_hand)<=Number(item.reorder_level));
  const prepItems=queue.data??[];
  const occupiedTableIds=new Set(locationOrders.map(order=>order.venue_table_id).filter(Boolean));
  const quickOrders=locationOrders.filter(order=>!order.venue_table_id).length;
  const isRefreshing=[orders,inventory,queue,venue,finance,cashSessions].some(query=>query.isFetching);
  const refetchAll=()=>{void Promise.all([orders.refetch(),inventory.refetch(),queue.refetch(),venue.refetch(),finance.refetch(),cashSessions.refetch()])};

  const metrics=[
    {label:'Net sales · month',value:can('finance.view')?(finance.data?money(finance.data.sales,currency):'—'):'—',note:can('finance.view')?'Completed payments less refunds':'Finance permission required',icon:Banknote,to:'/finance',enabled:can('finance.view')},
    {label:'Open orders',value:can('orders.view')?String(locationOrders.length):'—',note:can('orders.view')?`${locationOrders.filter(order=>order.status==='payment_due').length} awaiting payment`:'Orders permission required',icon:ShoppingBag,to:'/pos',enabled:can('orders.view')||can('orders.create')},
    {label:'Preparation queue',value:can('orders.view')?String(prepItems.length):'—',note:can('orders.view')?`${prepItems.filter(item=>item.preparation_status==='ready').length} ready to serve`:'Orders permission required',icon:Coffee,to:'/bar',enabled:can('orders.view')},
    {label:'Low stock',value:can('inventory.view')?String(lowStock.length):'—',note:can('inventory.view')?`${inventory.data?.length??0} tracked items`:'Inventory permission required',icon:CircleAlert,to:'/inventory',enabled:can('inventory.view')},
  ];

  return <section className="dashboard-page">
    <div className="page-heading dashboard-heading">
      <div><span className="eyebrow">LIVE OVERVIEW</span><h1>{greeting()}</h1><p>{activeLocation?.name??'Workspace'} · operational health, service flow and exceptions from live tenant data.</p></div>
      <div className="dashboard-heading-actions"><span className="live-pill"><span className="status-dot"/> Live operations</span><button type="button" className="secondary-button" onClick={refetchAll} disabled={isRefreshing}><RefreshCw size={15} className={isRefreshing?'spin':''}/>{isRefreshing?'Refreshing…':'Refresh'}</button></div>
    </div>

    <div className="metric-grid dashboard-metric-grid">
      {metrics.map(({label,value,note,icon:Icon,to,enabled})=><button type="button" className="metric-card dashboard-metric-card" key={label} disabled={!enabled} onClick={()=>enabled&&navigate(to)}>
        <div className="metric-card-top"><div className="metric-icon"><Icon size={19}/></div>{enabled&&<ArrowUpRight size={15}/>}</div>
        <span>{label}</span><strong>{value}</strong><small>{note}</small>
      </button>)}
    </div>

    <div className="dashboard-grid">
      <article className="panel dashboard-live-panel">
        <div className="panel-heading"><div><h2>Live service</h2><p>Table occupancy and active orders at {activeLocation?.name??'the current location'}.</p></div><button type="button" className="text-button" onClick={()=>navigate('/pos')}>Open POS <ArrowUpRight size={14}/></button></div>
        {!can('orders.view')?<div className="management-empty">Order visibility is not available for your role.</div>:venue.isError||orders.isError?<div className="error-state">Live service data could not be refreshed.</div>:<>
          {(venue.data??[]).map(area=>{const tableIds=new Set(area.tables.map(table=>table.id));const areaOrders=locationOrders.filter(order=>order.venue_table_id&&tableIds.has(order.venue_table_id));const occupied=area.tables.filter(table=>table.is_active&&occupiedTableIds.has(table.id)).length;const activeTables=area.tables.filter(table=>table.is_active).length;return <div className="service-row" key={area.id}><div><strong>{area.name}</strong><span>{occupied} / {activeTables} tables occupied</span></div><b>{areaOrders.length} orders</b></div>})}
          <div className="service-row"><div><strong>Counter & takeaway</strong><span>Orders without a service table</span></div><b>{quickOrders} orders</b></div>
        </>}
      </article>

      <article className="panel dashboard-attention-panel">
        <div className="panel-heading"><div><h2>Needs attention</h2><p>Operational signals that may need action now.</p></div><Clock3 size={19}/></div>
        {can('orders.view')&&<button type="button" className={`attention-row ${prepItems.length?'warning':''}`} onClick={()=>navigate('/bar')}><Coffee size={17}/><span><strong>{prepItems.length} preparation ticket{prepItems.length===1?'':'s'}</strong><small>{prepItems.filter(item=>item.preparation_status==='ready').length} ready · open queue</small></span><ArrowUpRight size={14}/></button>}
        {can('inventory.view')&&<button type="button" className={`attention-row ${lowStock.length?'danger':''}`} onClick={()=>navigate('/inventory')}><Boxes size={17}/><span><strong>{lowStock.length} low-stock item{lowStock.length===1?'':'s'}</strong><small>{lowStock.length?lowStock.slice(0,3).map(item=>item.name).join(', '):'Inventory is above reorder levels'}</small></span><ArrowUpRight size={14}/></button>}
        {can('payments.collect')&&<button type="button" className="attention-row" onClick={()=>navigate('/cash-register')}><WalletCards size={17}/><span><strong>{cashSessions.data?.length??0} open cash shift{(cashSessions.data?.length??0)===1?'':'s'}</strong><small>Review drawer and reconciliation</small></span><ArrowUpRight size={14}/></button>}
        {!can('orders.view')&&!can('inventory.view')&&!can('payments.collect')&&<div className="management-empty">No operational modules are available for this role.</div>}
      </article>
    </div>
  </section>;
}
