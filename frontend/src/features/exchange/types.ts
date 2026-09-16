export type CurrencyCode = 'ALL' | 'EUR' | 'USD' | 'GBP';

export type ExchangeRateInfo = {
  rate: string;
  source: string;
  effective_at: string;
  fetched_at: string;
} | null;

export type ExchangeRatesResponse = {
  base_currency: 'ALL';
  rates: Record<'EUR' | 'USD' | 'GBP', ExchangeRateInfo>;
};

export type CurrencyConversion = {
  from: CurrencyCode;
  to: CurrencyCode;
  input_amount: string;
  amount: string;
  rate: string;
  source: string;
  effective_at: string;
  inverse?: boolean;
};
