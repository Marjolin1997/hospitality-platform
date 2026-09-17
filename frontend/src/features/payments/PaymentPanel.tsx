import { useEffect, useMemo, useState } from 'react';
import { Banknote, CheckCircle2, CreditCard, Landmark, WalletCards } from 'lucide-react';
import { useCollectPayment, useCurrentCashSession } from './api';
import type { Currency, PaymentMethod } from './types';

type Props = { orderId: string; grandTotal: number; paidTotal: number; baseCurrency: Currency; onPaid?: () => void };
const methods:{value:PaymentMethod;label:string;icon:any}[]=[{value:'cash',label:'Cash',icon:Banknote},{value:'card',label:'Card',icon:CreditCard},{value:'bank_transfer',label:'Transfer',icon:Landmark},{value:'other',label:'Other',icon:WalletCards}];
const format=(value:number,currency:Currency)=>new Intl.NumberFormat(undefined,{style:'currency',currency,minimumFractionDigits:2,maximumFractionDigits:2}).format(value);

export function PaymentPanel({ orderId, grandTotal, paidTotal, baseCurrency, onPaid }: Props) {
  const session = useCurrentCashSession();
  const collect = useCollectPayment(orderId);
  const remaining = Math.max(grandTotal - paidTotal, 0);
  const [method, setMethod] = useState<PaymentMethod>('cash');
  const [currency, setCurrency] = useState<Currency>(baseCurrency);
  const [amount, setAmount] = useState(String(remaining));
  const [tendered, setTendered] = useState('');
  useEffect(()=>{setCurrency(baseCurrency);setAmount(String(remaining));setTendered('')},[orderId,baseCurrency,remaining]);
  const numericAmount=Number(amount); const numericTendered=Number(tendered||0);
  const validAmount=Number.isFinite(numericAmount)&&numericAmount>0&&numericAmount<=remaining;
  const validTendered=method!=='cash'||!tendered||(Number.isFinite(numericTendered)&&numericTendered>=numericAmount);
  const change=method==='cash'&&tendered&&validTendered?Math.max(numericTendered-numericAmount,0):0;
  const idempotencyKey = useMemo(() => crypto.randomUUID(), [orderId, method, currency, amount]);

  const submit = async () => {
    if(!validAmount||!validTendered)return;
    const payment = await collect.mutateAsync({
      cash_session_id: method === 'cash' ? session.data?.id : undefined,
      method, currency, amount: numericAmount,
      ...(method === 'cash' && tendered ? { tendered_amount: numericTendered } : {}),
      idempotency_key: idempotencyKey,
    });
    setTendered('');
    if (Number(payment.amount_base) >= remaining) onPaid?.();
  };

  return <section className="payment-panel">
    <header><div><span className="eyebrow">PAYMENT</span><h2>{format(remaining,baseCurrency)} remaining</h2></div><span className="status-pill">Split enabled</span></header>
    <div className="payment-methods" role="group" aria-label="Payment method">
      {methods.map(({value,label,icon:Icon}) => <button type="button" key={value} className={method === value ? 'active' : ''} aria-pressed={method===value} onClick={() => setMethod(value)}><Icon size={15}/><span>{label}</span></button>)}
    </div>
    <div className="payment-fields">
      <label><span>Currency</span><select value={currency} onChange={e => setCurrency(e.target.value as Currency)}>{['ALL','EUR','USD','GBP'].map(c => <option key={c}>{c}</option>)}</select></label>
      <label><span>Amount</span><input type="number" min="0.01" step="0.01" inputMode="decimal" value={amount} onChange={e => setAmount(e.target.value)} /></label>
      {method === 'cash' && <label><span>Cash received</span><input type="number" min="0" step="0.01" inputMode="decimal" value={tendered} onChange={e => setTendered(e.target.value)} placeholder={amount} /></label>}
    </div>
    {!validAmount&&amount!==''&&<p className="field-hint error">Enter an amount greater than zero and no more than the remaining balance.</p>}
    {method==='cash'&&tendered&&!validTendered&&<p className="field-hint error">Cash received must cover the payment amount.</p>}
    {method==='cash'&&change>0&&<div className="change-due"><span>Change due</span><strong>{format(change,currency)}</strong></div>}
    {method === 'cash' && session.isLoading && <p className="field-hint">Checking the active cash register…</p>}
    {method === 'cash' && !session.isLoading && !session.data && <p className="error-state">Open a cash register session before accepting cash.</p>}
    {collect.error && <p className="error-state">Payment could not be completed. Check the amount, currency rate and register session.</p>}
    {remaining<=0?<div className="payment-complete"><CheckCircle2 size={18}/> Payment complete</div>:<button className="primary-action full" disabled={collect.isPending||!validAmount||!validTendered||(method === 'cash' && !session.data)} onClick={submit}>{collect.isPending ? 'Processing payment…' : `Collect ${format(validAmount?numericAmount:0,currency)}`}</button>}
  </section>;
}
