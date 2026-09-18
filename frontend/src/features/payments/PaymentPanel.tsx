import { useEffect, useMemo, useState } from 'react';
import { Banknote, CheckCircle2, CreditCard, Landmark, RefreshCw, WalletCards } from 'lucide-react';
import { useCollectPayment, useCurrencyQuote, useOpenCashSessions, useRefundPayment } from './api';
import type { Currency, Payment, PaymentMethod } from './types';

type Props = { orderId: string; grandTotal: number; paidTotal: number; baseCurrency: Currency; payments: Payment[]; allowCollect: boolean; allowRefund: boolean };
const methods:{value:PaymentMethod;label:string;icon:any}[]=[{value:'cash',label:'Cash',icon:Banknote},{value:'card',label:'Card',icon:CreditCard},{value:'bank_transfer',label:'Transfer',icon:Landmark},{value:'other',label:'Other',icon:WalletCards}];
const format=(value:number,currency:Currency)=>new Intl.NumberFormat(undefined,{style:'currency',currency,minimumFractionDigits:2,maximumFractionDigits:2}).format(value);

export function PaymentPanel({ orderId, grandTotal, paidTotal, baseCurrency, payments, allowCollect, allowRefund }: Props) {
  const sessions = useOpenCashSessions();
  const collect = useCollectPayment(orderId);
  const remaining = Math.max(grandTotal - paidTotal, 0);
  const [method, setMethod] = useState<PaymentMethod>('cash');
  const [currency, setCurrency] = useState<Currency>(baseCurrency);
  const [amount, setAmount] = useState(String(remaining));
  const [tendered, setTendered] = useState('');
  const [cashSessionId,setCashSessionId]=useState('');
  const [refundPaymentId,setRefundPaymentId]=useState(''); const [refundAmount,setRefundAmount]=useState(''); const [refundReason,setRefundReason]=useState('');
  const refund=useRefundPayment(refundPaymentId);
  useEffect(()=>{setCurrency(baseCurrency);setAmount(String(remaining));setTendered('')},[orderId,baseCurrency,remaining]);
  useEffect(()=>{const rows=sessions.data??[];setCashSessionId(current=>rows.some(s=>s.id===current)?current:rows.length===1?rows[0].id:'')},[sessions.data]);
  const selectedSession=(sessions.data??[]).find(s=>s.id===cashSessionId);
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
      cash_session_id: method === 'cash' ? selectedSession?.id : undefined,
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
  const submitRefund=async()=>{if(!refundValid||!selectedRefund)return;await refund.mutateAsync({amount:refundValue,reason:refundReason.trim(),cash_session_id:selectedRefund.method==='cash'?selectedSession?.id:undefined,idempotency_key:refundKey});setRefundPaymentId('');setRefundAmount('');setRefundReason('');};

  return <section className="payment-panel">
    <header><div><span className="eyebrow">PAYMENT</span><h2>{format(remaining,baseCurrency)} remaining</h2></div><span className="status-pill">Partial & mixed enabled</span></header>
    {allowCollect&&remaining>0&&<><div className="payment-methods" role="group" aria-label="Payment method">
      {methods.map(({value,label,icon:Icon}) => <button type="button" key={value} className={method === value ? 'active' : ''} aria-pressed={method===value} onClick={() => {setMethod(value);if(value==='cash'){setCurrency(baseCurrency);setTendered('')}}}><Icon size={15}/><span>{label}</span></button>)}
    </div>
    <div className="payment-fields">
      <label><span>Currency</span><select disabled={method==='cash'} value={currency} onChange={e => {setCurrency(e.target.value as Currency);setTendered('')}}>{['ALL','EUR','USD','GBP'].map(c => <option key={c}>{c}</option>)}</select>{method==='cash'&&<small>Cash is reconciled in the business base currency.</small>}</label>
      <label><span>Amount</span><input type="number" min="0.01" step="0.01" inputMode="decimal" value={amount} onChange={e => setAmount(e.target.value)} /></label>
      {method === 'cash' && <><label><span>Cash register</span><select value={cashSessionId} onChange={e=>setCashSessionId(e.target.value)}><option value="">Select open register</option>{(sessions.data??[]).map(s=><option key={s.id} value={s.id}>{s.register?.name??'Register'} · {format(Number(s.expected_cash_live??s.opening_cash),s.base_currency)}</option>)}</select></label><label><span>Cash received</span><input type="number" min="0" step="0.01" inputMode="decimal" value={tendered} onChange={e => setTendered(e.target.value)} placeholder={amount} /></label></>}
    </div>
    {currency!==baseCurrency&&<div className="payment-quote">{quote.isLoading?<><RefreshCw size={15}/><span>Quoting {currency} in {baseCurrency}…</span></>:quote.isError?<span className="error-state">No usable exchange rate is configured for {currency}/{baseCurrency}.</span>:quote.data?<><span>{format(numericAmount||0,currency)} = <strong>{format(amountBase,baseCurrency)}</strong></span><small>Rate {quote.data.rate} · {quote.data.source}{quote.data.inverse?' · inverse':''}</small></>:null}</div>}
    {currency!==baseCurrency&&suggested>0&&<button type="button" className="payment-fill-button" onClick={()=>setAmount(suggested.toFixed(2))}>Use approx. {format(suggested,currency)} to cover remaining balance</button>}
    {Number.isFinite(amountBase)&&amountBase>remaining+.00005&&<p className="field-hint error">This payment converts to {format(amountBase,baseCurrency)}, above the remaining {format(remaining,baseCurrency)}.</p>}
    {!validAmount&&amount!==''&&currency===baseCurrency&&<p className="field-hint error">Enter an amount greater than zero and no more than the remaining balance.</p>}
    {method==='cash'&&tendered&&!validTendered&&<p className="field-hint error">Cash received must cover the payment amount.</p>}
    {method==='cash'&&change>0&&<div className="change-due"><span>Change due</span><strong>{format(change,currency)}</strong></div>}
    {method === 'cash' && sessions.isLoading && <p className="field-hint">Checking open cash registers…</p>}
    {method === 'cash' && !sessions.isLoading && (sessions.data??[]).length===0 && <p className="error-state">Open a cash register session before accepting cash.</p>}{method==='cash'&&(sessions.data??[]).length>1&&!selectedSession&&<p className="field-hint error">Select the register that will receive this cash payment.</p>}
    {collect.error && <p className="error-state">Payment could not be completed. Check the amount, currency rate and register session.</p>}
    <button className="primary-action full" disabled={collect.isPending||quote.isLoading||!validAmount||!validTendered||(method === 'cash' && !selectedSession)} onClick={submit}>{collect.isPending ? 'Processing payment…' : `Collect ${format(validAmount?numericAmount:0,currency)}`}</button></>}
    {remaining<=0&&<div className="payment-complete"><CheckCircle2 size={18}/> Payment complete</div>}
    {payments.length>0&&<div className="payment-history"><div className="panel-heading"><div><h3>Payment history</h3><p>Completed tenders, refunds and the net amount retained on this order.</p></div></div><div className="payment-history-list">{payments.map(p=>{const refunded=(p.refunds??[]).filter(r=>r.status==='completed').reduce((sum,r)=>sum+Number(r.amount),0);const net=Math.max(0,Number(p.amount)-refunded);return <article key={p.id} className="payment-history-row"><div><strong>{p.method.replace('_',' ')}</strong><small>{new Date(p.paid_at).toLocaleString()}</small></div><div className="payment-history-money"><span>{format(Number(p.amount),p.currency)}</span>{refunded>0&&<small>Refunded {format(refunded,p.currency)}</small>}<strong>Net {format(net,p.currency)}</strong></div></article>})}</div></div>}

    {allowRefund&&<div className="refund-panel"><div className="panel-heading"><div><h3>Refund payment</h3><p>Refund against the original payment and exchange-rate snapshot.</p></div></div>{refundablePayments.length===0?<p className="field-hint">No refundable completed payments.</p>:<><label><span>Payment</span><select value={refundPaymentId} onChange={e=>{const id=e.target.value;setRefundPaymentId(id);const p=refundablePayments.find(x=>x.id===id);setRefundAmount(p?String(p.refundable):'')}}><option value="">Select payment</option>{refundablePayments.map(p=><option key={p.id} value={p.id}>{p.method} · {format(p.refundable,p.currency)} refundable</option>)}</select></label>{selectedRefund&&<><label><span>Refund amount ({selectedRefund.currency})</span><input type="number" min="0.01" max={selectedRefund.refundable} step="0.01" inputMode="decimal" value={refundAmount} onChange={e=>setRefundAmount(e.target.value)}/></label><label><span>Reason</span><textarea minLength={3} maxLength={500} value={refundReason} onChange={e=>setRefundReason(e.target.value)} placeholder="Required for audit trail"/></label>{selectedRefund.method==='cash'&&<label><span>Refund from register</span><select value={cashSessionId} onChange={e=>setCashSessionId(e.target.value)}><option value="">Select open register</option>{(sessions.data??[]).map(s=><option key={s.id} value={s.id}>{s.register?.name??'Register'} · {format(Number(s.expected_cash_live??s.opening_cash),s.base_currency)}</option>)}</select></label>}{selectedRefund.method==='cash'&&!selectedSession&&<p className="error-state">Select an open cash register with enough reconciled cash for this refund.</p>}{refund.isError&&<p className="error-state">Refund could not be completed. Check refundable balance and cash session.</p>}<button className="danger-action full" disabled={!refundValid||refund.isPending||(selectedRefund.method==='cash'&&!selectedSession)} onClick={submitRefund}>{refund.isPending?'Processing refund…':`Refund ${format(refundValid?refundValue:0,selectedRefund.currency)}`}</button></>}</>}</div>}
  </section>;
}
