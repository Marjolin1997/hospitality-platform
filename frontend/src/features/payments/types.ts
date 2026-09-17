export type Currency='ALL'|'EUR'|'USD'|'GBP';
export type PaymentMethod='cash'|'card'|'bank_transfer'|'other';
export type CashRegister={id:string;location_id:string;name:string;code:string;is_active:boolean};
export type CashSession={id:string;cash_register_id:string;base_currency:Currency;opening_cash:string;expected_cash_live?:string;expected_cash?:string|null;counted_cash?:string|null;cash_difference?:string|null;status:'open'|'closed';opened_at:string;closed_at?:string|null};
export type CollectPaymentInput={cash_session_id?:string|null;method:PaymentMethod;currency:Currency;amount:number;tendered_amount?:number;idempotency_key:string;external_reference?:string};
export type PaymentRefund={id:string;payment_id:string;amount:string;amount_base:string;currency:Currency;base_currency:Currency;reason:string;status:string;refunded_at:string};
export type Payment={id:string;order_id:string;method:PaymentMethod;status:string;amount:string;amount_base:string;currency:Currency;base_currency:Currency;exchange_rate:string;tendered_amount:string|null;change_amount:string|null;paid_at:string;refunds?:PaymentRefund[]};
