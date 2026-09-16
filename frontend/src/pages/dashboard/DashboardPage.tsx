import { ArrowUpRight, Banknote, CircleAlert, Clock3, ShoppingBag } from 'lucide-react';

const metrics = [
  { label: 'Today revenue', value: '124,850 ALL', note: '+12.4% vs yesterday', icon: Banknote },
  { label: 'Open orders', value: '18', note: '6 awaiting preparation', icon: ShoppingBag },
  { label: 'Average ticket', value: '1,240 ALL', note: '101 paid orders', icon: ArrowUpRight },
  { label: 'Stock alerts', value: '7', note: '3 critical items', icon: CircleAlert },
];

export function DashboardPage() {
  return (
    <section>
      <div className="page-heading">
        <div><span className="eyebrow">OVERVIEW</span><h1>Good evening</h1><p>Here is what is happening across your café today.</p></div>
        <div className="live-pill"><span className="status-dot" /> Live operations</div>
      </div>
      <div className="metric-grid">
        {metrics.map(({ label, value, note, icon: Icon }) => (
          <article className="metric-card" key={label}>
            <div className="metric-icon"><Icon size={20} /></div>
            <span>{label}</span><strong>{value}</strong><small>{note}</small>
          </article>
        ))}
      </div>
      <div className="dashboard-grid">
        <article className="panel">
          <div className="panel-heading"><div><h2>Live service</h2><p>Current floor and order activity</p></div><button className="text-button">Open POS →</button></div>
          <div className="service-row"><div><strong>Terrace</strong><span>8 / 12 tables active</span></div><b>21 orders</b></div>
          <div className="service-row"><div><strong>Indoor</strong><span>5 / 10 tables active</span></div><b>14 orders</b></div>
          <div className="service-row"><div><strong>Quick sale</strong><span>Counter & takeaway</span></div><b>9 orders</b></div>
        </article>
        <article className="panel">
          <div className="panel-heading"><div><h2>Needs attention</h2><p>Operational exceptions</p></div><Clock3 size={20} /></div>
          <div className="alert-item warning"><strong>4 orders waiting over 10 min</strong><span>Bar station · review queue</span></div>
          <div className="alert-item danger"><strong>3 ingredients critically low</strong><span>Milk, tonic water, coffee beans</span></div>
          <div className="alert-item"><strong>Cash register open</strong><span>Opened 08:03 · by Manager</span></div>
        </article>
      </div>
    </section>
  );
}
