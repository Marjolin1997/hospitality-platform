import { useEffect, useMemo, useState } from 'react';
import { Banknote, CheckCircle2, CreditCard, Landmark, RefreshCw, WalletCards } from 'lucide-react';
import { useCollectPayment, useCurrencyQuote, useCurrentCashSession, useRefundPayment } from './api';
import type { Currency, Payment, PaymentMethod } from './types';

type Props = { orderId: string; grandTotal: number; paidTotal: number; baseCurrency: Currency; payments: Payment[]; allowCollect: boolean; allowRefund: boolean };
const methods:{value:PaymentMethod;label:string;icon:any}[]=[{value:'cash',label:'Cash',icon:Banknote},{value:'card',label:'Card',icon:CreditCard},{value:'bank_transfer',label:'Transfer',icon:Landmark},{value:'other',label:'Other',icon:WalletCards}];
const format=(value:number,currency:Currency)=>new Intl.NumberFormat(undefined,{style:'currency',currency,minimumFractionDigits:2,maximumFractionDigits:2}).format(value);

export function PaymentPanel({ orderId, grandTotal, paidTotal, baseCurrency, payments, allowCollect, allowRefund }: Props) {
  const session = useCurrentCashSession();
  const collect = useCollectPayment(orderId);
  const remaining = Math.max(grandTotal - paidTotal, 0);
  const [method, setMethod] = useState<PaymentMethod>('cash');
  const [currency, setCurrency] = useState<Currency>(baseCurrency);
  const [amount, setAmount] = useState(String(remaining));
  const [tendered, setTendered] = useState('');
  const [refundPaymentId,setRefundPaymentId]=useState(''); const [refundAmount,setRefundAmount]=useState(''); const [refundReason,setRefundReason]=useState('');
  const refund=useRefundPayment(refundPaymentId);
  useEffect(()=>{setCurrency(baseCurrency);setAmount(String(remaining));setTendered('')},[orderId,baseCurrency,remaining]);
  const numericAmount=Number(amount); const numericTendered=Number(tendered||0);
  const quote=useCurrencyQuote(numericAmount,currency,baseCurrency);
  const amountBase=currency===baseCurrency?numericAmount:Number(quote.data?.amount??NaN);
  const rate=currency===baseCurrency?1:Number(quote.data?.rate??NaN);
  const quoteReady=currency===baseCurrency||quote.isSuccess;
  const validAmount=Number.isFinite(numericAmount)&&numericAmount>0&&quoteReady&&Number.isFinite(amountBase)&&amountBase<=remaining+.00005;
  const validTendered=method!=='cash'||!tendered||(Number.isFinite(numericTendered)&&numericTendered>=numericAmount);
  const change=method==='cash'&&tendered&&validTendered?Math.max(numericTendered-numericAmount,0):0;
  const suggested=currency===baseCurrency?remaining:(Number.isFinite(rate)&&rate>0?remaining/rate:0);
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
    void payment;
  };

  const refundablePayments=payments.filter(p=>p.status==='completed').map(p=>{const refunded=(p.refunds??[]).filter(r=>r.status==='completed').reduce((s,r)=>s+Number(r.amount),0);return {...p,refundable:Math.max(0,Number(p.amount)-refunded)}}).filter(p=>p.refundable>.00005);
  const selectedRefund=refundablePayments.find(p=>p.id===refundPaymentId);
  const refundValue=Number(refundAmount); const refundValid=Boolean(selectedRefund)&&Number.isFinite(refundValue)&&refundValue>0&&refundValue<=Number(selectedRefund?.refundable??0)+.00005&&refundReason.trim().length>=3;
  const refundKey=useMemo(()=>crypto.randomUUID(),[refundPaymentId,refundAmount,refundReason]);
  const submitRefund=async()=>{if(!refundValid||!selectedRefund)return;await refund.mutateAsync({amount:refundValue,reason:refundReason.trim(),cash_session_id:selectedRefund.method==='cash'?session.data?.id:undefined,idempotency_key:refundKey});setRefundPaymentId('');setRefundAmount('');setRefundReason('');};

  return <section className="payment-panel">
    <header><div><span className="eyebrow">PAYMENT</span><h2>{format(remaining,baseCurrency)} remaining</h2></div><span className="status-pill">Partial & mixed enabled</span></header>
    {allowCollect&&remaining>0&&<><div className="payment-methods" role="group" aria-label="Payment method">
      {methods.map(({value,label,icon:Icon}) => <button type="button" key={value} className={method === value ? 'active' : ''} aria-pressed={method===value} onClick={() => setMethod(value)}><Icon size={15}/><span>{label}</span></button>)}
    </div>
    <div className="payment-fields">
      <label><span>Currency</span><select value={currency} onChange={e => {setCurrency(e.target.value as Currency);setTendered('')}}>{['ALL','EUR','USD','GBP'].map(c => <option key={c}>{c}</option>)}</select></label>
      <label><span>Amount</span><input type="number" min="0.01" step="0.01" inputMode="decimal" value={amount} onChange={e => setAmount(e.target.value)} /></label>
      {method === 'cash' && <label><span>Cash received</span><input type="number" min="0" step="0.01" inputMode="decimal" value={tendered} onChange={e => setTendered(e.target.value)} placeholder={amount} /></label>}
    </div>
    {currency!==baseCurrency&&<div className="payment-quote">{quote.isLoading?<><RefreshCw size={15}/><span>Quoting {currency} in {baseCurrency}…</span></>:quote.isError?<span className="error-state">No usable exchange rate is configured for {currency}/{baseCurrency}.</span>:quote.data?<><span>{format(numericAmount||0,currency)} = <strong>{format(amountBase,baseCurrency)}</strong></span><small>Rate {quote.data.rate} · {quote.data.source}{quote.data.inverse?' · inverse':''}</small></>:null}</div>}
    {currency!==baseCurrency&&suggested>0&&<button type="button" className="payment-fill-button" onClick={()=>setAmount(suggested.toFixed(2))}>Use approx. {format(suggested,currency)} to cover remaining balance</button>}
    {Number.isFinite(amountBase)&&amountBase>remaining+.00005&&<p className="field-hint error">This payment converts to {format(amountBase,baseCurrency)}, above the remaining {format(remaining,baseCurrency)}.</p>}
    {!validAmount&&amount!==''&&currency===baseCurrency&&<p className="field-hint error">Enter an amount greater than zero and no more than the remaining balance.</p>}
    {method==='cash'&&tendered&&!validTendered&&<p className="field-hint error">Cash received must cover the payment amount.</p>}
    {method==='cash'&&change>0&&<div className="change-due"><span>Change due</span><strong>{format(change,currency)}</strong></div>}
    {method === 'cash' && session.isLoading && <p className="field-hint">Checking the active cash register…</p>}
    {method === 'cash' && !session.isLoading && !session.data && <p className="error-state">Open a cash register session before accepting cash.</p>}
    {collect.error && <p className="error-state">Payment could not be completed. Check the amount, currency rate and register session.</p>}
    <button className="primary-action full" disabled={collect.isPending||quote.isLoading||!validAmount||!validTendered||(method === 'cash' && !session.data)} onClick={submit}>{collect.isPending ? 'Processing payment…' : `Collect ${format(validAmount?numericAmount:0,currency)}`}</button></>}
    {remaining<=0&&<div className="payment-complete"><CheckCircle2 size={18}/> Payment complete</div>}
    {allowRefund&&<div className="refund-panel"><div className="panel-heading"><div><h3>Refund payment</h3><p>Refund against the original payment and exchange-rate snapshot.</p></div></div>{refundablePayments.length===0?<p className="field-hint">No refundable completed payments.</p>:<><label><span>Payment</span><select value={refundPaymentId} onChange={e=>{const id=e.target.value;setRefundPaymentId(id);const p=refundablePayments.find(x=>x.id===id);setRefundAmount(p?String(p.refundable):'')}}><option value="">Select payment</option>{refundablePayments.map(p=><option key={p.id} value={p.id}>{p.method} · {format(p.refundable,p.currency)} refundable</option>)}</select></label>{selectedRefund&&<><label><span>Refund amount ({selectedRefund.currency})</span><input type="number" min="0.01" max={selectedRefund.refundable} step="0.01" inputMode="decimal" value={refundAmount} onChange={e=>setRefundAmount(e.target.value)}/></label><label><span>Reason</span><textarea minLength={3} maxLength={500} value={refundReason} onChange={e=>setRefundReason(e.target.value)} placeholder="Required for audit trail"/></label>{selectedRefund.method==='cash'&&!session.data&&<p className="error-state">An open cash register session at this location is required for a cash refund.</p>}{refund.isError&&<p className="error-state">Refund could not be completed. Check refundable balance and cash session.</p>}<button className="danger-action full" disabled={!refundValid||refund.isPending||(selectedRefund.method==='cash'&&!session.data)} onClick={submitRefund}>{refund.isPending?'Processing refund…':`Refund ${format(refundValid?refundValue:0,selectedRefund.currency)}`}</button></>}</>}</div>}
  </section>;
}
