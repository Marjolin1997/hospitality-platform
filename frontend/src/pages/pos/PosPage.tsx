import { Minus, Plus, Search, Trash2 } from 'lucide-react';

const products = [
  ['Espresso', '120 ALL', 'Coffee'], ['Cappuccino', '180 ALL', 'Coffee'], ['Flat White', '220 ALL', 'Coffee'],
  ['Fresh Orange', '300 ALL', 'Cold drinks'], ['Tonic Water', '200 ALL', 'Cold drinks'], ['Still Water', '120 ALL', 'Cold drinks'],
  ['Aperol Spritz', '600 ALL', 'Cocktails'], ['Gin & Tonic', '650 ALL', 'Cocktails'], ['Negroni', '700 ALL', 'Cocktails'],
];

export function PosPage() {
  return (
    <section className="pos-page">
      <div className="pos-catalog">
        <div className="page-heading compact"><div><span className="eyebrow">POINT OF SALE</span><h1>New order</h1><p>Table T-08 · Terrace · 2 guests</p></div><button className="secondary-button">Change table</button></div>
        <div className="search-box"><Search size={18}/><input placeholder="Search products or scan code…" aria-label="Search products" /></div>
        <div className="category-tabs"><button className="active">All</button><button>Coffee</button><button>Cold drinks</button><button>Cocktails</button><button>Beer</button><button>Food</button></div>
        <div className="product-grid">
          {products.map(([name, price, category]) => <button className="product-card" key={name}><span>{category}</span><strong>{name}</strong><b>{price}</b></button>)}
        </div>
      </div>
      <aside className="order-panel">
        <div className="order-heading"><div><span className="eyebrow">CURRENT ORDER</span><h2>Table T-08</h2></div><button className="icon-button" aria-label="Clear order"><Trash2 size={18}/></button></div>
        <div className="order-items">
          <OrderItem name="Cappuccino" detail="Oat milk" qty={2} total="440 ALL" />
          <OrderItem name="Gin & Tonic" detail="Hendrick's · Fever Tree" qty={1} total="750 ALL" />
          <OrderItem name="Still Water" detail="0.5 L" qty={1} total="120 ALL" />
        </div>
        <div className="order-summary"><div><span>Subtotal</span><b>1,310 ALL</b></div><div><span>Discount</span><b>0 ALL</b></div><div><span>VAT included</span><b>218 ALL</b></div><div className="total"><span>Total</span><strong>1,310 ALL</strong></div></div>
        <div className="order-actions"><button className="secondary-button full">Send to bar</button><button className="primary-button full">Pay · 1,310 ALL</button></div>
      </aside>
    </section>
  );
}

function OrderItem({ name, detail, qty, total }: { name: string; detail: string; qty: number; total: string }) {
  return <div className="order-item"><div className="order-item-main"><strong>{name}</strong><span>{detail}</span></div><div className="quantity"><button><Minus size={14}/></button><b>{qty}</b><button><Plus size={14}/></button></div><b>{total}</b></div>;
}
