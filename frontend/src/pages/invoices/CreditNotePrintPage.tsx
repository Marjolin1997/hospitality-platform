import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, Printer } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '../../features/auth/AuthProvider';
import { api } from '../../lib/api';

type CreditLine={
  id:string;position:number;product_name_snapshot:string;sku_snapshot:string|null;
  unit_code_snapshot:string;unit_label_snapshot:string;quantity:string;unit_price:string;
  discount_percent:string;tax_rate:string;line_subtotal:string;line_tax:string;line_total:string;
};
type PaymentSnapshot={id:string;method:string;method_label:string;amount:string;currency:string;amount_base:string;base_currency:string};
type OriginalInvoice={
  id:string;number:string;fiscal_invoice_number:string|null;business_name_snapshot:string|null;business_legal_name_snapshot:string|null;
  business_tax_number_snapshot:string|null;location_name_snapshot:string|null;location_address_snapshot:string|null;
  nslf:string|null;nivf:string|null;issued_at:string|null;
};
type CreditDetail={
  id:string;invoice_id:string;number:string;status:string;fiscalization_status:string;fiscal_invoice_type:string|null;
  fiscal_invoice_number:string|null;currency:string;subtotal:string;discount_total:string;tax_total:string;grand_total:string;
  invoice_number_snapshot:string;original_invoice_nslf_snapshot:string|null;original_invoice_issued_at_snapshot:string|null;
  fiscal_operator_code_snapshot:string|null;fiscal_business_unit_code_snapshot:string|null;fiscal_tcr_code_snapshot:string|null;
  customer_name_snapshot:string|null;customer_tax_number_snapshot:string|null;reason:string;issued_at:string|null;fiscalized_at:string|null;
  nslf:string|null;nivf:string|null;verification_url:string|null;qr_payload:string|null;fiscalization_error:string|null;
  refunded_total:string;lines:CreditLine[];payments:PaymentSnapshot[];original_invoice:OriginalInvoice;
};

const fixed=(value:string|number,digits=2)=>Number(value||0).toFixed(digits);
const neg=(value:string|number)=>fixed(-Math.abs(Number(value||0)));

function fiscalDate(value:string|null,timeZone:string){
  if(!value)return '—';
  const parts=new Intl.DateTimeFormat('en-CA',{timeZone,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).formatToParts(new Date(value));
  const get=(t:string)=>parts.find(p=>p.type===t)?.value??'';
  return `${get('year')}-${get('month')}-${get('day')} ${get('hour')}:${get('minute')}:${get('second')}`;
}

export function CreditNotePrintPage(){
  const {creditNoteId}=useParams();
  const navigate=useNavigate();
  const {activeBusiness}=useAuth();
  const q=useQuery({
    queryKey:['credit-note-detail',creditNoteId],
    enabled:Boolean(creditNoteId),
    queryFn:()=>api.get<{data:CreditDetail}>(`/invoice-credit-notes/${creditNoteId}`).then(r=>r.data.data),
  });

  if(q.isLoading)return <main className="invoice-document-state">Duke përgatitur faturën korrigjuese…</main>;
  if(q.isError||!q.data)return <main className="invoice-document-state error">Fatura korrigjuese nuk mund të ngarkohej.</main>;

  const credit=q.data;
  const original=credit.original_invoice;
  const timeZone=activeBusiness?.timezone??'Europe/Tirane';
  const qrValue=credit.verification_url||credit.qr_payload||'';
  const seller=original.business_legal_name_snapshot||original.business_name_snapshot||'—';
  const vatGroups=Object.values(credit.lines.reduce<Record<string,{rate:number;base:number;tax:number}>>((acc,line)=>{
    const key=String(Number(line.tax_rate));acc[key]??={rate:Number(line.tax_rate),base:0,tax:0};
    acc[key].base-=Math.abs(Number(line.line_subtotal));acc[key].tax-=Math.abs(Number(line.line_tax));return acc;
  },{})).sort((a,b)=>a.rate-b.rate);

  return <main className="fiscal-invoice-shell">
    <div className="invoice-screen-toolbar">
      <button className="secondary-button" onClick={()=>navigate('/invoices')}><ArrowLeft size={16}/> Faturat</button>
      <div>
        <span className={`status-badge ${credit.fiscalization_status==='fiscalized'?'success':'warning'}`}>{credit.fiscalization_status.replaceAll('_',' ')}</span>
        <button className="secondary-button" onClick={()=>window.open(`/invoice-credit-notes/${credit.id}/receipt/80`,'_blank','noopener,noreferrer')}>80mm</button>
        <button className="secondary-button" onClick={()=>window.open(`/invoice-credit-notes/${credit.id}/receipt/58`,'_blank','noopener,noreferrer')}>58mm</button>
        <button className="primary-button" onClick={()=>window.print()}><Printer size={16}/> Print / PDF</button>
      </div>
    </div>

    <article className="fiscal-invoice-document corrective-document">
      <h1>FATURË KORRIGJUESE</h1>
      <section className="invoice-box invoice-party-grid">
        <span>Shitësi:</span><strong>{seller}</strong>
        <span>Adresa:</span><strong>{original.location_address_snapshot??original.location_name_snapshot??'—'}</strong>
        <span>NUIS/NIPT:</span><strong>{original.business_tax_number_snapshot??'—'}</strong>
      </section>

      <section className="invoice-box invoice-party-grid">
        <span>Data dhe ora e lëshimit:</span><strong>{fiscalDate(credit.issued_at,timeZone)}</strong>
        <span>Numri i dokumentit:</span><strong>{credit.fiscal_invoice_number??credit.number}</strong>
        <span>Fatura origjinale:</span><strong>{original.fiscal_invoice_number??credit.invoice_number_snapshot}</strong>
        <span>NSLF origjinal:</span><strong>{credit.original_invoice_nslf_snapshot??original.nslf??'—'}</strong>
        <span>Data e faturës origjinale:</span><strong>{fiscalDate(credit.original_invoice_issued_at_snapshot||original.issued_at,timeZone)}</strong>
        <span>Arsyeja:</span><strong>{credit.reason}</strong>
        <span>Operatori:</span><strong>{credit.fiscal_operator_code_snapshot??'—'}</strong>
        <span>Business Unit:</span><strong>{credit.fiscal_business_unit_code_snapshot??'—'}</strong>
        <span>TCR:</span><strong>{credit.fiscal_tcr_code_snapshot??'—'}</strong>
      </section>

      <div className="invoice-table-wrap">
        <table className="fiscal-invoice-table invoice-items-table">
          <thead><tr><th>Përshkrimi</th><th>Njësia</th><th>Sasia</th><th>Çmimi pa TVSH</th><th>Zbritje %</th><th>TVSH %</th><th>Baza</th><th>TVSH</th><th>Totali</th></tr></thead>
          <tbody>
            {credit.lines.map(line=>{
              const taxFactor=1+(Number(line.tax_rate)/100);
              const unitNet=taxFactor===0?Number(line.unit_price):Number(line.unit_price)/taxFactor;
              return <tr key={line.id}><td>{line.product_name_snapshot}</td><td>{line.unit_label_snapshot||line.unit_code_snapshot}</td><td>-{fixed(line.quantity,3)}</td><td>{neg(unitNet)}</td><td>{Number(line.discount_percent)>0?fixed(line.discount_percent):''}</td><td>{fixed(line.tax_rate)}</td><td>{neg(line.line_subtotal)}</td><td>{neg(line.line_tax)}</td><td>{neg(line.line_total)}</td></tr>;
            })}
            <tr className="invoice-total-row"><td colSpan={6}></td><th colSpan={2}>Vlera pa TVSH</th><td>{neg(credit.subtotal)}</td></tr>
            <tr className="invoice-total-row"><td colSpan={6}></td><th colSpan={2}>TVSH</th><td>{neg(credit.tax_total)}</td></tr>
            <tr className="invoice-total-row grand"><td colSpan={6}></td><th colSpan={2}>Totali korrigjues ({credit.currency})</th><td>{neg(credit.grand_total)}</td></tr>
          </tbody>
        </table>
      </div>

      <h2 className="invoice-section-title">Shpërndarja e TVSH-së</h2>
      <table className="fiscal-invoice-table vat-table"><thead><tr><th>Norma</th><th>Baza ({credit.currency})</th><th>TVSH ({credit.currency})</th></tr></thead><tbody>{vatGroups.map(v=><tr key={v.rate}><td>{fixed(v.rate)}</td><td>{fixed(v.base)}</td><td>{fixed(v.tax)}</td></tr>)}</tbody></table>

      <section className="invoice-fiscal-codes">
        <div><span>NSLF:</span><strong>{credit.nslf??'Në pritje të fiskalizimit'}</strong></div>
        <div><span>NIVF:</span><strong>{credit.nivf??'Në pritje të përgjigjes së sistemit tatimor'}</strong></div>
      </section>

      <section className="invoice-payment-qr-grid">
        <div><h2 className="invoice-section-title">Mënyra e pagesës së korrigjuar:</h2><table className="fiscal-invoice-table payment-table"><thead><tr><th>Lloji</th><th>Sasi ({credit.currency})</th></tr></thead><tbody>{credit.payments.map(p=><tr key={p.id}><td>{p.method_label}</td><td>{neg(p.currency===credit.currency?p.amount:p.amount_base)}</td></tr>)}</tbody></table></div>
        <div className="invoice-qr">{qrValue?<QRCodeSVG value={qrValue} size={170} level="M" marginSize={2}/>:<div className="invoice-qr-pending">QR<br/><small>pas fiskalizimit</small></div>}</div>
      </section>
    </article>
  </main>;
}
