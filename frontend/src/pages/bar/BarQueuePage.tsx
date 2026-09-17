import { CheckCircle2, ChefHat, Clock3, RefreshCw, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { useAuth } from '../../features/auth/AuthProvider';
import { useBarQueue, useTransitionBarItem } from '../../features/bar/api';

export function BarQueuePage() {
  const {can}=useAuth(); const queue=useBarQueue(); const transition=useTransitionBarItem(); const [busyId,setBusyId]=useState<string|null>(null);
  const groups=[['sent','New'],['preparing','Preparing'],['ready','Ready']] as const; const canPrepare=can('orders.prepare');
  if(queue.isLoading)return <div className="page-state">Loading preparation queue…</div>;
  if(queue.isError)return <div className="panel management-state error"><strong>Bar queue unavailable</strong><span>Live preparation data could not be loaded.</span><button className="secondary-button" onClick={()=>queue.refetch()}><RefreshCw size={15}/> Try again</button></div>;
  const items=queue.data??[];
  return <main className="management-page"><header className="page-heading"><div><span className="eyebrow">LIVE PREPARATION</span><h1>Bar Queue</h1><p>Preparation tickets refresh every five seconds. Each action follows the operational state machine.</p></div><span className="status-pill success">LIVE · 5s</span></header>
    {!canPrepare&&<div className="permission-banner"><ShieldCheck size={18}/><div><strong>Read-only preparation view</strong><span>Your role can monitor tickets but cannot change preparation status.</span></div></div>}
    {transition.isError&&<p className="error-state">The ticket could not be moved. It may have changed on another device; the queue has been refreshed.</p>}
    <div className="kanban-grid">{groups.map(([status,label])=>{const column=items.filter(i=>i.preparation_status===status);return <section className="queue-column" key={status}><header><h2>{label}</h2><span>{column.length}</span></header>{column.length===0&&<div className="queue-empty">No {label.toLowerCase()} tickets</div>}{column.map(item=><article className={`queue-ticket ${status}`} key={item.id}><div className="ticket-meta"><span>Order {item.order.number}</span><span>{item.preparation_station??'Bar'}</span></div><h3>{Number(item.quantity)} × {item.product_name_snapshot}</h3>{item.note&&<p className="ticket-note"><strong>Note</strong> {item.note}</p>}{canPrepare&&<button className="primary-button full" disabled={transition.isPending&&busyId===item.id} onClick={()=>{setBusyId(item.id);transition.mutate({id:item.id,status:status==='sent'?'preparing':status==='preparing'?'ready':'served'},{onSettled:()=>{setBusyId(null);queue.refetch()}})}}>{transition.isPending&&busyId===item.id?'Updating…':status==='sent'?<><ChefHat size={17}/> Start preparing</>:status==='preparing'?<><CheckCircle2 size={17}/> Mark ready</>:<><Clock3 size={17}/> Mark served</>}</button>}</article>)}</section>})}</div>
  </main>;
}
