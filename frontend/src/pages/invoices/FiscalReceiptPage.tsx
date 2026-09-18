import { useQuery } from '@tanstack/react-query';
import { Printer } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { useLocation, useParams } from 'react-router-dom';
import { useAuth } from '../../features/auth/AuthProvider';
import { api } from '../../lib/api';

type Line={id:string;product_name_snapshot:string;quantity:string;line_total:string;tax_rate:string};
type Payment={id:string;method_label:string;amount:string;currency:string;amount_base:string};
type InvoiceReceipt={id:string;number:string;fiscal_invoice_number:string|null;fiscalization_status:string;currency:string;grand_total:string;tax_total:string;business_name_snapshot:string|null;business_legal_name_snapshot:string|null;business_tax_number_snapshot:string|null;location_name_snapshot:string|null;location_address_snapshot:string|null;fiscal_operator_code_snapshot:string|null;fiscal_business_unit_code_snapshot:string|null;fiscal_tcr_code_snapshot:string|null;nslf:string|null;nivf:string|null;verification_url:string|null;qr_payload:string|null;issued_at:string|null;fiscalized_at:string|null;lines:Line[];payments:Payment[]};
type CreditReceipt={id:string;number:string;fiscal_invoice_number:string|null;fiscalization_status:string;currency:string;grand_total:string;tax_total:string;reason:string;fiscal_operator_code_snapshot:string|null;fiscal_business_unit_code_snapshot:string|null;fiscal_tcr_code_snapshot:string|null;nslf:string|null;nivf:string|null;verification_url:string|null;qr_payload:string|null;issued_at:string|null;fiscalized_at:string|null;original_invoice_nslf_snapshot:string|null;lines:Line[];payments:Payment[];original_invoice:InvoiceReceipt};

const fixed=(v:string|number)=>Number(v||0).toFixed(2);
const qty=(v:string)=>Number(v||0).toFixed(3).replace(/0+$/,'').replace(/\.$/,'');
function when(value:string|null,tz:string){return value?new Intl.DateTimeFormat('sq-AL',{timeZone:tz,dateStyle:'short',timeStyle:'medium'}).format(new Date(value)):'—';}

export function FiscalReceiptPage(){
  const {invoiceId,creditNoteId,paper}=useParams();
  const isCredit=Boolean(creditNoteId);
  const {activeBusiness}=useAuth();
  const location=useLocation();
  const q=useQuery<InvoiceReceipt|CreditReceipt>({
    queryKey:['fiscal-receipt',isCredit?'credit':'invoice',creditNoteId||invoiceId],
    enabled:Boolean(creditNoteId||invoiceId),
    queryFn:async ():Promise<InvoiceReceipt|CreditReceipt>=>{
      if(isCredit){
        return api.get<{data:CreditReceipt}>(`/invoice-credit-notes/${creditNoteId}`).then(r=>r.data.data);
      }

      return api.get<{data:InvoiceReceipt}>(`/invoices/${invoiceId}`).then(r=>r.data.data);
    },
  });
  if(q.isLoading)return <main className="receipt-state">Duke përgatitur kuponin…</main>;
  if(q.isError||!q.data)return <main className="receipt-state error">Kuponi nuk mund të ngarkohej.</main>;

  const width=paper==='58'?'58':'80';
  const tz=activeBusiness?.timezone??'Europe/Tirane';
  const doc=q.data as InvoiceReceipt|CreditReceipt;
  const original=isCredit?(doc as CreditReceipt).original_invoice:doc as InvoiceReceipt;
  const negative=isCredit?-1:1;
  const qr=doc.verification_url||doc.qr_payload||'';

  return <main className={`thermal-receipt-shell paper-${width}`} data-path={location.pathname}>
    <div className="receipt-screen-toolbar"><button className="primary-button" onClick={()=>window.print()}><Printer size={16}/> Print {width}mm</button></div>
    <article className="thermal-receipt">
      <header>
        <strong>{original.business_legal_name_snapshot||original.business_name_snapshot||'—'}</strong>
        <span>{original.location_address_snapshot||original.location_name_snapshot||'—'}</span>
        <span>NUIS/NIPT: {original.business_tax_number_snapshot||'—'}</span>
        <h1>{isCredit?'FATURË KORRIGJUESE':'FATURË'}</h1>
      </header>

      <section className="receipt-meta">
        <div><span>Nr:</span><strong>{doc.fiscal_invoice_number||doc.number}</strong></div>
        <div><span>Data:</span><strong>{when(doc.issued_at,tz)}</strong></div>
        <div><span>Operator:</span><strong>{doc.fiscal_operator_code_snapshot||'—'}</strong></div>
        <div><span>Njësia:</span><strong>{doc.fiscal_business_unit_code_snapshot||'—'}</strong></div>
        <div><span>TCR:</span><strong>{doc.fiscal_tcr_code_snapshot||'—'}</strong></div>
        {isCredit&&<><div><span>Origjinal:</span><strong>{(doc as CreditReceipt).original_invoice.fiscal_invoice_number||(doc as CreditReceipt).original_invoice.number}</strong></div><div><span>Arsye:</span><strong>{(doc as CreditReceipt).reason}</strong></div></>}
      </section>

      <div className="receipt-rule"/>
      <section className="receipt-lines">
        {doc.lines.map(line=><div className="receipt-line" key={line.id}><div><strong>{line.product_name_snapshot}</strong><span>{negative<0?'-':''}{qty(line.quantity)} × TVSH {fixed(line.tax_rate)}%</span></div><strong>{fixed(negative*Math.abs(Number(line.line_total)))}</strong></div>)}
      </section>
      <div className="receipt-rule"/>
      <section className="receipt-totals">
        <div><span>TVSH</span><strong>{fixed(negative*Math.abs(Number(doc.tax_total)))}</strong></div>
        <div className="receipt-grand"><span>TOTAL {doc.currency}</span><strong>{fixed(negative*Math.abs(Number(doc.grand_total)))}</strong></div>
      </section>

      <section className="receipt-payments"><strong>Pagesa</strong>{doc.payments.map(p=><div key={p.id}><span>{p.method_label}</span><span>{fixed(negative*Math.abs(Number(p.currency===doc.currency?p.amount:p.amount_base)))}</span></div>)}</section>
      <div className="receipt-rule"/>
      <section className="receipt-fiscal"><span>NSLF</span><code>{doc.nslf||'PENDING'}</code><span>NIVF</span><code>{doc.nivf||'PENDING'}</code>{isCredit&&<><span>NSLF origjinal</span><code>{(doc as CreditReceipt).original_invoice_nslf_snapshot||'—'}</code></>}</section>
      <div className="receipt-qr">{qr?<QRCodeSVG value={qr} size={width==='58'?140:180} level="M" marginSize={1}/>:<div className="receipt-qr-pending">QR pas fiskalizimit</div>}</div>
      <footer>Faleminderit!</footer>
    </article>
  </main>;
}
