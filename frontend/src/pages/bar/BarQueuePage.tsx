import { CheckCircle2, ChefHat, Clock3 } from 'lucide-react';
import { useBarQueue, useTransitionBarItem } from '../../features/bar/api';

export function BarQueuePage() {
  const queue=useBarQueue(); const transition=useTransitionBarItem();
  const groups=[['sent','New'],['preparing','Preparing'],['ready','Ready']] as const;
  if(queue.isLoading) return <div className="page-state">Loading preparation queue…</div>;
  if(queue.isError) return <div className="page-state"><strong>Bar queue unavailable</strong><button onClick={()=>queue.refetch()}>Try again</button></div>;
  return <main className="management-page"><header className="page-heading"><div><span className="eyebrow">LIVE PREPARATION</span><h1>Bar Queue</h1><p>New tickets refresh automatically. Move each item forward with one touch.</p></div><span className="status-pill success">LIVE · 5s</span></header><div className="kanban-grid">{groups.map(([status,label])=><section className="queue-column" key={status}><header><h2>{label}</h2><span>{queue.data?.filter(i=>i.preparation_status===status).length??0}</span></header>{queue.data?.filter(i=>i.preparation_status===status).map(item=><article className={`queue-ticket ${status}`} key={item.id}><div className="ticket-meta"><span>Order {item.order.number}</span><span>{item.preparation_station??'Bar'}</span></div><h3>{Number(item.quantity)} × {item.product_name_snapshot}</h3>{item.note&&<p className="ticket-note">Note: {item.note}</p>}<button className="primary-button full" disabled={transition.isPending} onClick={()=>transition.mutate({id:item.id,status:status==='sent'?'preparing':status==='preparing'?'ready':'served'})}>{status==='sent'?<><ChefHat size={17}/> Start preparing</>:status==='preparing'?<><CheckCircle2 size={17}/> Mark ready</>:<><Clock3 size={17}/> Mark served</>}</button></article>)}</section>)}</div></main>;
}
