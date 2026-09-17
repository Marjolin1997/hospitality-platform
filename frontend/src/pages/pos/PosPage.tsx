import { Check, Minus, Plus, Search, Trash2, X } from 'lucide-react';
import { type ReactNode, useMemo, useState } from 'react';
import { useCatalog } from '../../features/catalog/useCatalog';
import type { Product } from '../../features/catalog/types';
import { useOrderCart } from '../../features/pos/useOrderCart';
import { useCreateOrder } from '../../features/orders/useCreateOrder';
import { useSendOrder } from '../../features/orders/api';
import type { Order } from '../../features/orders/types';
import { PaymentPanel } from '../../features/payments/PaymentPanel';
import { useVenue, type VenueTable } from '../../features/venue/api';

const money = new Intl.NumberFormat('sq-AL', { style: 'currency', currency: 'ALL', maximumFractionDigits: 0 });

export function PosPage() {
  const { data: categories = [], isLoading, isError, refetch } = useCatalog();
  const cart = useOrderCart();
  const createOrder = useCreateOrder();
  const venue = useVenue();
  const [categoryId, setCategoryId] = useState('all');
  const [search, setSearch] = useState('');
  const [selectedTable, setSelectedTable] = useState<VenueTable | null>(null);
  const [tableOpen, setTableOpen] = useState(false);
  const [activeOrder, setActiveOrder] = useState<Order | null>(null);
  const sendOrder = useSendOrder(activeOrder?.id);

  const products = useMemo(() => categories.filter(c => categoryId === 'all' || c.id === categoryId)
    .flatMap(c => c.products.map(product => ({ ...product, categoryName: c.name })))
    .filter(product => `${product.name} ${product.sku ?? ''} ${product.barcode ?? ''}`.toLowerCase().includes(search.trim().toLowerCase())), [categories, categoryId, search]);

  const create = async () => {
    const order = await createOrder.mutateAsync({
      location_id: selectedTable?.location_id ?? venue.data?.[0]?.location_id ?? '',
      venue_table_id: selectedTable?.id ?? null,
      type: selectedTable ? 'table' : 'counter',
      items: cart.toOrderItems(),
    });
    setActiveOrder(order); cart.clear();
  };

  const paidTotal = activeOrder?.payments?.filter(p => p.status === 'completed').reduce((sum, p) => sum + Number(p.amount_base), 0) ?? 0;

  return <section className="pos-page">
    <div className="pos-catalog"><div className="page-heading compact"><div><span className="eyebrow">POINT OF SALE</span><h1>{activeOrder ? `Order ${activeOrder.number}` : 'New order'}</h1><p>{selectedTable ? `Table ${selectedTable.name}` : 'Counter sale · select a table when serving seated guests.'}</p></div><button className="secondary-button" onClick={() => setTableOpen(true)}>{selectedTable ? `Table ${selectedTable.name}` : 'Select table'}</button></div>
    <label className="search-box"><Search size={18}/><input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search products, SKU or barcode…" /></label>
    <div className="category-tabs"><button className={categoryId === 'all' ? 'active' : ''} onClick={() => setCategoryId('all')}>All</button>{categories.map(c => <button key={c.id} className={categoryId === c.id ? 'active' : ''} onClick={() => setCategoryId(c.id)}>{c.name}</button>)}</div>
    {isLoading && <PosState title="Loading menu…" detail="Fetching the latest menu."/>}{isError && <PosState title="Menu unavailable" detail="Check your connection and try again." action={<button onClick={() => refetch()}>Try again</button>}/>}<div className="product-grid">{products.map(p => <ProductCard key={p.id} product={p} categoryName={p.categoryName} onAdd={() => cart.add(p)}/>)}</div></div>

    <aside className="order-panel"><div className="order-heading"><div><span className="eyebrow">{activeOrder ? 'ACTIVE ORDER' : 'CURRENT ORDER'}</span><h2>{selectedTable ? `Table ${selectedTable.name}` : 'Counter'}</h2></div>{!activeOrder && <button className="icon-button" onClick={cart.clear}><Trash2 size={18}/></button>}</div>
    {!activeOrder ? <><div className="order-items">{cart.lines.length === 0 && <div className="empty-order"><strong>No items yet</strong><span>Tap a product to add it.</span></div>}{cart.lines.map(({product,quantity}) => <OrderItem key={product.id} product={product} qty={quantity} onDecrease={() => cart.changeQuantity(product.id,-1)} onIncrease={() => cart.changeQuantity(product.id,1)}/>)}</div><div className="order-summary"><div><span>Items</span><b>{cart.lines.reduce((s,l)=>s+l.quantity,0)}</b></div><div className="total"><span>Preview total</span><strong>{money.format(cart.total)}</strong></div><small>Backend verifies prices, taxes and final total.</small></div><button className="primary-button full" disabled={!cart.lines.length || createOrder.isPending} onClick={create}>{createOrder.isPending ? 'Creating…' : `Create order · ${money.format(cart.total)}`}</button></>
    : <><div className="order-items">{activeOrder.items.map(item => <div className="order-item" key={item.id}><div className="order-item-main"><strong>{item.product_name_snapshot}</strong><span>{item.quantity} × {money.format(Number(item.unit_price))}</span></div><span className={`status-pill ${item.preparation_status}`}>{item.preparation_status}</span><b>{money.format(Number(item.line_total))}</b></div>)}</div><div className="order-summary"><div><span>Tax</span><b>{money.format(Number(activeOrder.tax_total))}</b></div><div className="total"><span>Authoritative total</span><strong>{money.format(Number(activeOrder.grand_total))}</strong></div></div><button className="secondary-button full" disabled={sendOrder.isPending || !activeOrder.items.some(i => i.preparation_status === 'pending')} onClick={async () => setActiveOrder(await sendOrder.mutateAsync())}>{sendOrder.isPending ? 'Sending…' : 'Send new items to bar'}</button><PaymentPanel orderId={activeOrder.id} grandTotal={Number(activeOrder.grand_total)} paidTotal={paidTotal} baseCurrency={activeOrder.currency as 'ALL'|'EUR'|'USD'|'GBP'} onPaid={() => setActiveOrder({...activeOrder,status:'paid'})}/><button className="ghost-button full" onClick={() => {setActiveOrder(null);setSelectedTable(null);}}>New order</button></>}
    </aside>
    {tableOpen && <div className="modal-backdrop" role="presentation"><div className="modal-card" role="dialog" aria-modal="true"><header><div><span className="eyebrow">TABLES</span><h2>Select service table</h2></div><button className="icon-button" onClick={() => setTableOpen(false)}><X/></button></header>{venue.isLoading ? <PosState title="Loading tables…" detail="Getting the venue layout."/> : <div className="venue-areas">{venue.data?.map(area => <section key={area.id}><h3>{area.name}</h3><div className="table-grid">{area.tables.map(table => <button key={table.id} className={selectedTable?.id === table.id ? 'table-card active' : 'table-card'} onClick={() => {setSelectedTable(table);setTableOpen(false);}}><strong>{table.name}</strong><span>{table.capacity ? `${table.capacity} seats` : 'Table'}</span>{selectedTable?.id === table.id && <Check size={16}/>}</button>)}</div></section>)}</div>}<button className="secondary-button full" onClick={() => {setSelectedTable(null);setTableOpen(false);}}>Use counter sale</button></div></div>}
  </section>;
}

function ProductCard({product,categoryName,onAdd}:{product:Product;categoryName:string;onAdd:()=>void}) { return <button className="product-card" onClick={onAdd}><span>{categoryName}</span><strong>{product.name}</strong><b>{money.format(Number(product.sale_price))}</b></button>; }
function OrderItem({product,qty,onDecrease,onIncrease}:{product:Product;qty:number;onDecrease:()=>void;onIncrease:()=>void}) { return <div className="order-item"><div className="order-item-main"><strong>{product.name}</strong><span>{money.format(Number(product.sale_price))} each</span></div><div className="quantity"><button onClick={onDecrease}><Minus size={14}/></button><b>{qty}</b><button onClick={onIncrease}><Plus size={14}/></button></div><b>{money.format(Number(product.sale_price)*qty)}</b></div>; }
function PosState({title,detail,action}:{title:string;detail:string;action?:ReactNode}) { return <div className="pos-state"><strong>{title}</strong><span>{detail}</span>{action}</div>; }
