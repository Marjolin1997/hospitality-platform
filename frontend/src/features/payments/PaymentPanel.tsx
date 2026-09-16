import { useMemo, useState } from 'react';
import { useCollectPayment, useCurrentCashSession } from './api';
import type { Currency, PaymentMethod } from './types';

type Props = { orderId: string; grandTotal: number; paidTotal: number; baseCurrency: Currency; onPaid?: () => void };

export function PaymentPanel({ orderId, grandTotal, paidTotal, baseCurrency, onPaid }: Props) {
  const session = useCurrentCashSession();
  const collect = useCollectPayment(orderId);
  const remaining = Math.max(grandTotal - paidTotal, 0);
  const [method, setMethod] = useState<PaymentMethod>('cash');
  const [currency, setCurrency] = useState<Currency>(baseCurrency);
  const [amount, setAmount] = useState(String(remaining));
  const [tendered, setTendered] = useState('');
  const idempotencyKey = useMemo(() => crypto.randomUUID(), [orderId, method, currency, amount]);

  const submit = async () => {
    const payment = await collect.mutateAsync({
      cash_session_id: method === 'cash' ? session.data?.id : undefined,
      method, currency, amount: Number(amount),
      ...(method === 'cash' && tendered ? { tendered_amount: Number(tendered) } : {}),
      idempotency_key: idempotencyKey,
    });
    setTendered('');
    if (Number(payment.amount_base) >= remaining) onPaid?.();
  };

  return <section className="payment-panel">
    <header><div><span className="eyebrow">Payment</span><h2>{remaining.toLocaleString()} {baseCurrency} remaining</h2></div><span className="status-pill">Split enabled</span></header>
    <div className="payment-methods">
      {(['cash','card','bank_transfer','other'] as PaymentMethod[]).map(value => <button key={value} className={method === value ? 'active' : ''} onClick={() => setMethod(value)}>{value.replace('_',' ')}</button>)}
    </div>
    <div className="payment-fields">
      <label>Currency<select value={currency} onChange={e => setCurrency(e.target.value as Currency)}>{['ALL','EUR','USD','GBP'].map(c => <option key={c}>{c}</option>)}</select></label>
      <label>Amount<input inputMode="decimal" value={amount} onChange={e => setAmount(e.target.value)} /></label>
      {method === 'cash' && <label>Cash received<input inputMode="decimal" value={tendered} onChange={e => setTendered(e.target.value)} placeholder={amount} /></label>}
    </div>
    {method === 'cash' && !session.data && <p className="error-state">Open a cash register session before accepting cash.</p>}
    {collect.error && <p className="error-state">Payment could not be completed. Check the amount, currency rate and register session.</p>}
    <button className="primary-action" disabled={collect.isPending || remaining <= 0 || (method === 'cash' && !session.data)} onClick={submit}>{collect.isPending ? 'Processing…' : 'Collect payment'}</button>
  </section>;
}
