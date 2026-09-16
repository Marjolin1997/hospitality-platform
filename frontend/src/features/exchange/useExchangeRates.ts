import { useMutation, useQuery } from '@tanstack/react-query';
import { api } from '../../lib/api';
import type { CurrencyCode, CurrencyConversion, ExchangeRatesResponse } from './types';

export function useExchangeRates() {
  return useQuery({
    queryKey: ['exchange-rates'],
    queryFn: async () => {
      const response = await api.get<{ data: ExchangeRatesResponse }>('/exchange-rates');
      return response.data.data;
    },
    staleTime: 5 * 60 * 1000,
  });
}

export function useCurrencyConversion() {
  return useMutation({
    mutationFn: async (input: { amount: string; from: CurrencyCode; to: CurrencyCode }) => {
      const response = await api.post<{ data: CurrencyConversion }>('/exchange-rates/convert', input);
      return response.data.data;
    },
  });
}
