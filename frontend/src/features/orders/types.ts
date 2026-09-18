import type { Payment } from '../payments/types';
export type CreateOrderItemInput={product_id:string;quantity:number;note?:string};
export type CreateOrderInput={location_id:string;venue_table_id?:string|null;type:'table'|'takeaway'|'counter';items:CreateOrderItemInput[]};
export type OrderItem={id:string;order_id:string;product_id:string|null;product_name_snapshot:string;quantity:string;unit_price:string;tax_rate:string;line_total:string;preparation_station:string|null;preparation_status:string;note:string|null;order?:Order};
export type OrderInvoiceCreditNote={id:string;number:string;status:string;currency:string;grand_total:string;reason:string};
export type OrderInvoice={id:string;number:string;status:string;issued_at:string|null;credit_note?:OrderInvoiceCreditNote|null};
export type Order={id:string;number:string;location_id:string;venue_table_id:string|null;type:string;status:string;currency:string;subtotal:string;discount_total?:string;tax_total:string;grand_total:string;opened_at:string;items:OrderItem[];payments?:Payment[];invoice?:OrderInvoice|null};
