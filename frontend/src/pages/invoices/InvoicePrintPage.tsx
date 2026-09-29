import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, Printer } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '../../features/auth/AuthProvider';
import { api } from '../../lib/api';

type InvoiceLine={
  id:string;position:number;product_name_snapshot:string;sku_snapshot:string|null;
  unit_code_snapshot:string;unit_label_snapshot:string;quantity:string;unit_price:string;unit_price_foreign:string|null;
  discount_percent:string;tax_rate:string;line_subtotal:string;line_tax:string;line_total:string;
  line_subtotal_foreign:string|null;line_tax_foreign:string|null;line_total_foreign:string|null;
};
type PaymentSnapshot={
  id:string;position:number;method:string;method_label:string;amount:string;currency:string;
  amount_base:string;base_currency:string;exchange_rate:string;external_reference:string|null;
};
type InvoiceDetail={
  id:string;number:string;status:string;fiscalization_status:string;fiscal_invoice_type:string|null;fiscal_invoice_number:string|null;
  currency:string;invoice_currency:string|null;exchange_rate:string|null;exchange_rate_source:string|null;exchange_rate_effective_at:string|null;
  subtotal:string;discount_total:string;tax_total:string;grand_total:string;
  subtotal_foreign:string|null;discount_total_foreign:string|null;tax_total_foreign:string|null;grand_total_foreign:string|null;
  order_number_snapshot:string|null;business_name_snapshot:string|null;business_legal_name_snapshot:string|null;
  business_tax_number_snapshot:string|null;location_name_snapshot:string|null;location_address_snapshot:string|null;
  fiscal_operator_code_snapshot:string|null;fiscal_business_unit_code_snapshot:string|null;fiscal_tcr_code_snapshot:string|null;
  customer_name:string|null;customer_tax_number:string|null;nslf:string|null;nivf:string|null;
  verification_url:string|null;qr_payload:string|null;issued_at:string|null;fiscalized_at:string|null;
  fiscalization_error:string|null;
  lines:InvoiceLine[];payments:PaymentSnapshot[];
  fiscalization_readiness?:{ready:boolean;missing:string[];environment:string|null;provider:string|null};
};

const fixed=(value:string|number, digits=2)=>Number(value||0).toFixed(digits);

function fiscalDate(value:string|null, timeZone:string){
  if(!value)return '—';
  const d=new Date(value);
  const parts=new Intl.DateTimeFormat('en-CA',{timeZone,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).formatToParts(d);
  const get=(t:string)=>parts.find(p=>p.type===t)?.value??'';
  return `${get('year')}-${get('month')}-${get('day')} ${get('hour')}:${get('minute')}:${get('second')}`;
}

export function InvoicePrintPage(){
  const {invoiceId}=useParams();
  const navigate=useNavigate();
  const {activeBusiness}=useAuth();
  const q=useQuery({
    queryKey:['invoice-detail',invoiceId],
    enabled:Boolean(invoiceId),
    queryFn:()=>api.get<{data:InvoiceDetail}>(`/invoices/${invoiceId}`).then(r=>r.data.data),
  });

  if(q.isLoading)return <main className="invoice-document-state">Duke përgatitur faturën…</main>;
  if(q.isError||!q.data)return <main className="invoice-document-state error">Fatura nuk mund të ngarkohej.</main>;

  const invoice=q.data;
  const timeZone=activeBusiness?.timezone??'Europe/Tirane';
  const displayCurrency=invoice.invoice_currency||invoice.currency;
  const isForeign=displayCurrency!==invoice.currency&&Boolean(invoice.exchange_rate&&invoice.grand_total_foreign);
  const displaySubtotal=isForeign?invoice.subtotal_foreign!:invoice.subtotal;
  const displayDiscount=isForeign?invoice.discount_total_foreign!:invoice.discount_total;
  const displayTax=isForeign?invoice.tax_total_foreign!:invoice.tax_total;
  const displayGrand=isForeign?invoice.grand_total_foreign!:invoice.grand_total;

  const vatGroups=Object.values(invoice.lines.reduce<Record<string,{rate:number;base:number;tax:number}>>((acc,line)=>{
    const key=String(Number(line.tax_rate));
    acc[key]??={rate:Number(line.tax_rate),base:0,tax:0};
    acc[key].base+=Number(isForeign?line.line_subtotal_foreign:line.line_subtotal);
    acc[key].tax+=Number(isForeign?line.line_tax_foreign:line.line_tax);
    return acc;
  },{})).sort((a,b)=>a.rate-b.rate);

  const cashLike=invoice.payments.some(p=>['cash','card'].includes(p.method));
  const invoiceType=invoice.fiscal_invoice_type==='CASH'||(!invoice.fiscal_invoice_type&&cashLike)
    ?'Fatura e parave të gatshme'
    :invoice.fiscal_invoice_type==='NONCASH'?'Faturë pa para në dorë':'Në pritje të klasifikimit fiskal';
  const qrValue=invoice.verification_url||invoice.qr_payload||'';
  const demoQr=Boolean(invoice.qr_payload?.startsWith('DEMO|LOCAL_ONLY'));
  const seller=invoice.business_legal_name_snapshot||invoice.business_name_snapshot||'—';

  return <main className="fiscal-invoice-shell">
    <div className="invoice-screen-toolbar">
      <button type="button" className="secondary-button" onClick={()=>navigate('/invoices')}><ArrowLeft size={16}/> Faturat</button>
      <div>
        <span className={`status-badge ${invoice.fiscalization_status==='fiscalized'?'success':'warning'}`}>{invoice.fiscalization_status.replaceAll('_',' ')}</span>
        <button type="button" className="secondary-button" onClick={()=>window.open(`/invoices/${invoice.id}/receipt/80`,'_blank','noopener,noreferrer')}>80mm</button>
        <button type="button" className="secondary-button" onClick={()=>window.open(`/invoices/${invoice.id}/receipt/58`,'_blank','noopener,noreferrer')}>58mm</button>
        <button type="button" className="primary-button" onClick={()=>window.print()}><Printer size={16}/> Print / PDF</button>
      </div>
    </div>

    <article className="fiscal-invoice-document">
      <h1>FATURË</h1>

      <section className="invoice-box invoice-party-grid">
        <span>Shitësi:</span><strong>{seller}</strong>
        <span>Adresa:</span><strong>{invoice.location_address_snapshot??invoice.location_name_snapshot??'—'}</strong>
        <span>Numri Unik i Identifikimit:</span><strong>{invoice.business_tax_number_snapshot??'—'}</strong>
      </section>

      <section className="invoice-box invoice-party-grid">
        <span>Data dhe ora e lëshimit të faturës:</span><strong>{fiscalDate(invoice.issued_at,timeZone)}</strong>
        <span>Numri fiskal i Faturës:</span><strong>{invoice.fiscal_invoice_number??invoice.number}</strong>
        {invoice.fiscal_invoice_number&&<><span>Referenca e brendshme:</span><strong>{invoice.number}</strong></>}
        <span>Operatori:</span><strong>{invoice.fiscal_operator_code_snapshot??'—'}</strong>
        <span>Kodi i vendit të ushtrimit të veprimtarisë:</span><strong>{invoice.fiscal_business_unit_code_snapshot??'—'}</strong>
        <span>Kodi i pajisjes elektronike:</span><strong>{invoice.fiscal_tcr_code_snapshot??'—'}</strong>
        <span>Lloji i Faturës:</span><strong>{invoiceType}</strong>
        <span>Monedha e faturës:</span><strong>{displayCurrency}</strong>
      </section>

      {isForeign&&<section className="invoice-exchange-box">
        <div><span>Kursi i këmbimit</span><strong>1 {displayCurrency} = {Number(invoice.exchange_rate).toFixed(2)} {invoice.currency}</strong></div>
        <div><span>Burimi</span><strong>{invoice.exchange_rate_source??'—'}</strong></div>
        <div><span>Efektiv më</span><strong>{fiscalDate(invoice.exchange_rate_effective_at,timeZone)}</strong></div>
      </section>}

      <div className="invoice-table-wrap">
        <table className="fiscal-invoice-table invoice-items-table">
          <thead><tr>
            <th>Përshkrimi i Mallit ose Shërbimit</th><th>Njësia e Matjes</th><th>Sasia</th>
            <th>Çmimi për njësi pa TVSH ({displayCurrency})</th><th>Zbritje %</th><th>Norma e TVSH</th>
            <th>Vlera pa TVSH ({displayCurrency})</th><th>TVSH ({displayCurrency})</th><th>Vlera Totale ({displayCurrency})</th>
          </tr></thead>
          <tbody>
            {invoice.lines.map(line=>{
              const grossUnit=Number(isForeign?line.unit_price_foreign:line.unit_price);
              const taxFactor=1+(Number(line.tax_rate)/100);
              const unitNet=taxFactor===0?grossUnit:grossUnit/taxFactor;
              return <tr key={line.id}>
                <td>{line.product_name_snapshot}</td><td>{line.unit_label_snapshot||line.unit_code_snapshot}</td>
                <td>{fixed(line.quantity,3)}</td><td>{fixed(unitNet)}</td><td>{Number(line.discount_percent)>0?fixed(line.discount_percent):''}</td>
                <td>{fixed(line.tax_rate)}</td><td>{fixed(isForeign?line.line_subtotal_foreign!:line.line_subtotal)}</td><td>{fixed(isForeign?line.line_tax_foreign!:line.line_tax)}</td><td>{fixed(isForeign?line.line_total_foreign!:line.line_total)}</td>
              </tr>;
            })}
            <tr className="invoice-total-row"><td colSpan={6}></td><th colSpan={2}>Vlera pa TVSH ({displayCurrency})</th><td>{fixed(displaySubtotal)}</td></tr>
            {Number(displayDiscount)>0&&<tr className="invoice-total-row"><td colSpan={6}></td><th colSpan={2}>Zbritje totale ({displayCurrency})</th><td>-{fixed(displayDiscount)}</td></tr>}
            <tr className="invoice-total-row"><td colSpan={6}></td><th colSpan={2}>Vlera totale e TVSH-së ({displayCurrency})</th><td>{fixed(displayTax)}</td></tr>
            <tr className="invoice-total-row grand"><td colSpan={6}></td><th colSpan={2}>Totali për t'u paguar ({displayCurrency})</th><td>{fixed(displayGrand)}</td></tr>
            {isForeign&&<tr className="invoice-total-row base-equivalent"><td colSpan={6}></td><th colSpan={2}>Kundërvlera në {invoice.currency}</th><td>{fixed(invoice.grand_total)}</td></tr>}
          </tbody>
        </table>
      </div>

      {isForeign&&<section className="invoice-dual-total-box">
        <div><span>Totali i faturës</span><strong>{fixed(displayGrand)} {displayCurrency}</strong></div>
        <div><span>Kundërvlera</span><strong>{fixed(invoice.grand_total)} {invoice.currency}</strong></div>
        <div><span>Kursi i fiksuar</span><strong>1 {displayCurrency} = {Number(invoice.exchange_rate).toFixed(2)} {invoice.currency}</strong></div>
      </section>}

      <h2 className="invoice-section-title">Shpërndarja e TVSH-së</h2>
      <table className="fiscal-invoice-table vat-table">
        <thead><tr><th>Norma e TVSH-së</th><th>Baza e tatueshme ({displayCurrency})</th><th>Vlera e TVSH-së ({displayCurrency})</th></tr></thead>
        <tbody>{vatGroups.map(v=><tr key={v.rate}><td>{fixed(v.rate)}</td><td>{fixed(v.base)}</td><td>{fixed(v.tax)}</td></tr>)}</tbody>
      </table>

      <section className="invoice-fiscal-codes">
        <div><span>Numri i sigurisë së lëshuesit të faturës (NSLF):</span><strong>{invoice.nslf??'Në pritje të fiskalizimit'}</strong></div>
        <div><span>Numri identifikues i veçantë i faturës (NIVF):</span><strong>{invoice.nivf??'Në pritje të përgjigjes së sistemit tatimor'}</strong></div>
      </section>

      <section className="invoice-payment-qr-grid">
        <div>
          <h2 className="invoice-section-title">Mënyra e pagesës:</h2>
          <table className="fiscal-invoice-table payment-table">
            <thead><tr><th>Lloji</th><th>Shuma e paguar</th><th>Kundërvlera</th></tr></thead>
            <tbody>{invoice.payments.map(p=><tr key={p.id}><td>{p.method_label}</td><td>{fixed(p.amount)} {p.currency}</td><td>{fixed(p.amount_base)} {p.base_currency}</td></tr>)}</tbody>
          </table>
        </div>
        <div className="invoice-qr">
          {qrValue?<QRCodeSVG value={qrValue} size={170} level="M" marginSize={2}/>:<div className="invoice-qr-pending">QR<br/><small>gjenerohet pas fiskalizimit</small></div>}
          {invoice.verification_url&&<small>Skano për verifikim në sistemin tatimor</small>}
          {demoQr&&<small>DEMO QR · vetëm local/test</small>}
        </div>
      </section>

      {!invoice.fiscalization_readiness?.ready&&invoice.fiscalization_status!=='fiscalized'&&<section className="invoice-readiness-note">
        <strong>Fatura është draft financiar, jo ende faturë e fiskalizuar.</strong>
        <span>Mungon: {invoice.fiscalization_readiness?.missing.join(', ')||'konfigurimi i fiskalizimit'}.</span>
      </section>}
    </article>
  </main>;
}
