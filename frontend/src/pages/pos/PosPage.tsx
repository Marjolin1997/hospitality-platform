import { Minus, Plus, Search, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useCatalog } from '../../features/catalog/useCatalog';
import type { Product } from '../../features/catalog/types';
import { useOrderCart } from '../../features/pos/useOrderCart';

const money = new Intl.NumberFormat('sq-AL', { style: 'currency', currency: 'ALL', maximumFractionDigits: 0 });

export function PosPage() {
  const { data: categories = [], isLoading, isError, refetch } = useCatalog();
  const { lines, add, changeQuantity, clear, total } = useOrderCart();
  const [categoryId, setCategoryId] = useState<string>('all');
  const [search, setSearch] = useState('');

  const products = useMemo(() => categories
    .filter((category) => categoryId === 'all' || category.id === categoryId)
    .flatMap((category) => category.products.map((product) => ({ ...product, categoryName: category.name })))
    .filter((product) => `${product.name} ${product.sku ?? ''} ${product.barcode ?? ''}`.toLowerCase().includes(search.trim().toLowerCase())),
  [categories, categoryId, search]);

  return (
    <section className="pos-page">
      <div className="pos-catalog">
        <div className="page-heading compact">
          <div><span className="eyebrow">POINT OF SALE</span><h1>New order</h1><p>Select a table or use quick sale before checkout.</p></div>
          <button className="secondary-button">Select table</button>
        </div>

        <label className="search-box"><Search size={18}/><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search products, SKU or barcode…" aria-label="Search products" /></label>

        <div className="category-tabs" aria-label="Product categories">
          <button className={categoryId === 'all' ? 'active' : ''} onClick={() => setCategoryId('all')}>All</button>
          {categories.map((category) => <button className={categoryId === category.id ? 'active' : ''} onClick={() => setCategoryId(category.id)} key={category.id}>{category.name}</button>)}
        </div>

        {isLoading && <PosState title="Loading menu…" detail="Fetching the latest products for this business." />}
        {isError && <PosState title="Menu unavailable" detail="We could not load the catalog." action={<button className="secondary-button" onClick={() => refetch()}>Try again</button>} />}
        {!isLoading && !isError && products.length === 0 && <PosState title="No products found" detail="Change the category or search term." />}

        <div className="product-grid">
          {products.map((product) => <ProductCard key={product.id} product={product} categoryName={product.categoryName} onAdd={() => add(product)} />)}
        </div>
      </div>

      <aside className="order-panel">
        <div className="order-heading">
          <div><span className="eyebrow">CURRENT ORDER</span><h2>Quick sale</h2></div>
          <button className="icon-button" aria-label="Clear order" onClick={clear} disabled={lines.length === 0}><Trash2 size={18}/></button>
        </div>

        <div className="order-items">
          {lines.length === 0 && <div className="empty-order"><strong>No items yet</strong><span>Tap a product to add it to this order.</span></div>}
          {lines.map(({ product, quantity }) => <OrderItem key={product.id} product={product} qty={quantity} onDecrease={() => changeQuantity(product.id, -1)} onIncrease={() => changeQuantity(product.id, 1)} />)}
        </div>

        <div className="order-summary">
          <div><span>Items</span><b>{lines.reduce((sum, line) => sum + line.quantity, 0)}</b></div>
          <div className="total"><span>Total</span><strong>{money.format(total)}</strong></div>
          <small>Final tax and totals are calculated authoritatively by the backend when the order is created.</small>
        </div>

        <div className="order-actions">
          <button className="secondary-button full" disabled={lines.length === 0}>Send to bar</button>
          <button className="primary-button full" disabled={lines.length === 0}>Create order · {money.format(total)}</button>
        </div>
      </aside>
    </section>
  );
}

function ProductCard({ product, categoryName, onAdd }: { product: Product; categoryName: string; onAdd: () => void }) {
  return <button className="product-card" onClick={onAdd}><span>{categoryName}</span><strong>{product.name}</strong><b>{money.format(Number(product.sale_price))}</b></button>;
}

function OrderItem({ product, qty, onDecrease, onIncrease }: { product: Product; qty: number; onDecrease: () => void; onIncrease: () => void }) {
  return <div className="order-item"><div className="order-item-main"><strong>{product.name}</strong><span>{money.format(Number(product.sale_price))} each</span></div><div className="quantity"><button onClick={onDecrease} aria-label={`Decrease ${product.name}`}><Minus size={14}/></button><b>{qty}</b><button onClick={onIncrease} aria-label={`Increase ${product.name}`}><Plus size={14}/></button></div><b>{money.format(Number(product.sale_price) * qty)}</b></div>;
}

function PosState({ title, detail, action }: { title: string; detail: string; action?: React.ReactNode }) {
  return <div className="pos-state"><strong>{title}</strong><span>{detail}</span>{action}</div>;
}
