import { CheckCircle2, ChefHat, Clock3, RefreshCw, Search, ShieldCheck, X } from 'lucide-react';
import { useState } from 'react';
import { useAuth } from '../../features/auth/AuthProvider';
import { useBarQueue, useTransitionBarItem } from '../../features/bar/api';

export function BarQueuePage() {
  const {can}=useAuth();
  const queue=useBarQueue();
  const transition=useTransitionBarItem();
  const [busyId,setBusyId]=useState<string|null>(null);
  const [search,setSearch]=useState('');
  const [station,setStation]=useState('all');
  const groups=[['sent','New'],['preparing','Preparing'],['ready','Ready']] as const;
  const canPrepare=can('orders.prepare');

  if(queue.isLoading)return <div className="page-state">Loading preparation queue…</div>;
  if(queue.isError)return <div className="panel management-state error"><strong>Bar queue unavailable</strong><span>Live preparation data could not be loaded.</span><button type="button" className="secondary-button" onClick={()=>queue.refetch()}><RefreshCw size={15}/> Try again</button></div>;

  const items=queue.data??[];
  const stations=Array.from(new Set(items.map(item=>item.preparation_station??'Bar'))).sort();
  const term=search.trim().toLowerCase();
  const filtered=items.filter(item=>(station==='all'||(item.preparation_station??'Bar')===station)&&(!term||[item.product_name_snapshot,item.order.number,item.note,item.preparation_station].filter(Boolean).some(value=>String(value).toLowerCase().includes(term))));

  return <main className="management-page bar-queue-page">
    <header className="page-heading"><div><span className="eyebrow">LIVE PREPARATION</span><h1>Bar Queue</h1><p>Preparation tickets refresh every five seconds. Search, filter and move each item through the operational state machine.</p></div><div className="page-heading-actions"><span className="status-pill success">LIVE · 5s</span><button type="button" className="secondary-button" disabled={queue.isFetching} onClick={()=>queue.refetch()}><RefreshCw size={15} className={queue.isFetching?'spin':''}/>{queue.isFetching?'Refreshing…':'Refresh'}</button></div></header>

    {!canPrepare&&<div className="permission-banner"><ShieldCheck size={18}/><div><strong>Read-only preparation view</strong><span>Your role can monitor tickets but cannot change preparation status.</span></div></div>}
    {transition.isError&&<p className="error-state">The ticket could not be moved. It may have changed on another device; the queue has been refreshed.</p>}

    <section className="panel queue-toolbar-panel">
      <div className="toolbar-actions queue-toolbar">
        <label className="search-box compact-search"><Search size={16}/><input aria-label="Search preparation tickets" value={search} onChange={e=>setSearch(e.target.value)} placeholder="Search order, item or note…"/>{search&&<button type="button" className="search-clear" aria-label="Clear ticket search" onClick={()=>setSearch('')}><X size={14}/></button>}</label>
        <select aria-label="Filter preparation station" value={station} onChange={e=>setStation(e.target.value)}><option value="all">All stations</option>{stations.map(value=><option key={value} value={value}>{value}</option>)}</select>
        <span className="toolbar-result-count">{filtered.length} of {items.length} tickets</span>
      </div>
    </section>

    <div className="kanban-grid">{groups.map(([status,label])=>{
      const column=filtered.filter(item=>item.preparation_status===status);
      return <section className="queue-column" key={status}><header><div><h2>{label}</h2><small>{status==='sent'?'Awaiting preparation':status==='preparing'?'Being prepared':'Awaiting service'}</small></div><span>{column.length}</span></header>
        {column.length===0&&<div className="queue-empty">No {label.toLowerCase()} tickets match the current filters.</div>}
        {column.map(item=><article className={`queue-ticket ${status}`} key={item.id}><div className="ticket-meta"><span>Order {item.order.number}</span><span>{item.preparation_station??'Bar'}</span></div><h3>{Number(item.quantity)} × {item.product_name_snapshot}</h3>{item.note&&<p className="ticket-note"><strong>Note</strong> {item.note}</p>}{canPrepare&&<button type="button" className="primary-button full" disabled={transition.isPending} onClick={()=>{setBusyId(item.id);transition.mutate({id:item.id,status:status==='sent'?'preparing':status==='preparing'?'ready':'served'},{onSettled:()=>{setBusyId(null);queue.refetch()}})}}>{transition.isPending&&busyId===item.id?'Updating…':status==='sent'?<><ChefHat size={17}/> Start preparing</>:status==='preparing'?<><CheckCircle2 size={17}/> Mark ready</>:<><Clock3 size={17}/> Mark served</>}</button>}</article>)}
      </section>})}
    </div>
  </main>;
}
