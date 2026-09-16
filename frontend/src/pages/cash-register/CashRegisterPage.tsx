import { useMemo, useState } from 'react';
import { useCashMovement, useCashRegisters, useCloseCashSession, useCurrentCashSession, useOpenCashSession } from '../../features/payments/api';
import type { Currency } from '../../features/payments/types';

const ALL_DENOMINATIONS = [5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5];

export function CashRegisterPage() {
  const registers = useCashRegisters();
  const current = useCurrentCashSession();
  const open = useOpenCashSession();
  const movement = useCashMovement(current.data?.id);
  const close = useCloseCashSession(current.data?.id);
  const [registerId, setRegisterId] = useState('');
  const [openingCash, setOpeningCash] = useState('0');
  const [movementType, setMovementType] = useState<'cash_in'|'cash_out'>('cash_in');
  const [movementAmount, setMovementAmount] = useState('');
  const [movementCurrency, setMovementCurrency] = useState<Currency>('ALL');
  const [reason, setReason] = useState('');
  const [counts, setCounts] = useState<Record<number, number>>({});
  const [closingNote, setClosingNote] = useState('');
  const countedCash = useMemo(() => ALL_DENOMINATIONS.reduce((sum, d) => sum + d * (counts[d] ?? 0), 0), [counts]);

  if (current.isLoading || registers.isLoading) return <div className="page-state">Loading cash register…</div>;

  if (!current.data) return <main className="management-page"><header className="page-heading"><div><span className="eyebrow">Cash control</span><h1>Open register shift</h1><p>Start the shift with a verified opening float before accepting cash.</p></div></header><section className="panel form-panel"><label>Register<select value={registerId} onChange={e => setRegisterId(e.target.value)}><option value="">Select register</option>{registers.data?.map(r => <option key={r.id} value={r.id}>{r.name} · {r.code}</option>)}</select></label><label>Opening cash<input inputMode="decimal" value={openingCash} onChange={e => setOpeningCash(e.target.value)} /></label><button className="primary-action" disabled={!registerId || open.isPending} onClick={() => open.mutate({cash_register_id: registerId, opening_cash: Number(openingCash)})}>Open shift</button></section></main>;

  const expected = Number(current.data.expected_cash_live ?? current.data.opening_cash);
  const difference = countedCash - expected;

  return <main className="management-page"><header className="page-heading"><div><span className="eyebrow">Cash control</span><h1>{current.data.status === 'open' ? 'Register shift open' : 'Register shift'}</h1><p>All sales, payouts and manual movements reconcile against the ledger.</p></div><span className="status-pill success">OPEN</span></header>
    <section className="metric-grid"><article className="metric-card"><span>Opening float</span><strong>{Number(current.data.opening_cash).toLocaleString()} {current.data.base_currency}</strong></article><article className="metric-card"><span>Expected now</span><strong>{expected.toLocaleString()} {current.data.base_currency}</strong></article><article className="metric-card"><span>Counted</span><strong>{countedCash.toLocaleString()} ALL</strong></article><article className="metric-card"><span>Variance</span><strong>{difference.toLocaleString()} ALL</strong></article></section>
    <div className="two-column-layout"><section className="panel"><h2>Cash in / out</h2><div className="segmented-control"><button className={movementType === 'cash_in' ? 'active' : ''} onClick={() => setMovementType('cash_in')}>Cash in</button><button className={movementType === 'cash_out' ? 'active' : ''} onClick={() => setMovementType('cash_out')}>Cash out</button></div><label>Currency<select value={movementCurrency} onChange={e => setMovementCurrency(e.target.value as Currency)}>{['ALL','EUR','USD','GBP'].map(c => <option key={c}>{c}</option>)}</select></label><label>Amount<input inputMode="decimal" value={movementAmount} onChange={e => setMovementAmount(e.target.value)} /></label><label>Reason<input value={reason} onChange={e => setReason(e.target.value)} placeholder="Supplier payment, petty cash, extra float…" /></label><button className="secondary-action" disabled={!movementAmount || !reason || movement.isPending} onClick={async () => { await movement.mutateAsync({type: movementType, amount: Number(movementAmount), currency: movementCurrency, reason}); setMovementAmount(''); setReason(''); }}>Record movement</button></section>
    <section className="panel"><h2>Close shift · cash count</h2><p>Count the physical ALL denominations. The system compares the result with the ledger.</p><div className="denomination-grid">{ALL_DENOMINATIONS.map(d => <label key={d}><span>{d.toLocaleString()} ALL</span><input type="number" min="0" value={counts[d] ?? ''} onChange={e => setCounts({...counts, [d]: Math.max(0, Number(e.target.value))})} /></label>)}</div><label>Closing note<textarea value={closingNote} onChange={e => setClosingNote(e.target.value)} placeholder="Optional reconciliation note" /></label><div className="reconciliation-summary"><span>Expected <strong>{expected.toLocaleString()} ALL</strong></span><span>Counted <strong>{countedCash.toLocaleString()} ALL</strong></span><span>Difference <strong>{difference.toLocaleString()} ALL</strong></span></div><button className="danger-action" disabled={close.isPending} onClick={() => close.mutate({counted_cash: countedCash, closing_note: closingNote || undefined})}>Close & reconcile shift</button></section></div>
  </main>;
}
