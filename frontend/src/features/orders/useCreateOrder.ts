import { useMutation } from '@tanstack/react-query';
import { api } from '../../lib/api';
import type { CreateOrderInput, Order } from './types';

export function useCreateOrder() {
  return useMutation({
    mutationFn: async (input: CreateOrderInput) => {
      const response = await api.post<{ data: Order }>('/orders', input);
      return response.data.data;
    },
  });
}
