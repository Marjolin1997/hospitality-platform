import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../lib/api';
import type { Order } from './types';

export function useOrder(orderId?: string) {
  return useQuery({ queryKey: ['orders', orderId], enabled: Boolean(orderId), queryFn: async () => (await api.get<{data: Order}>(`/orders/${orderId}`)).data.data });
}

export function useSendOrder(orderId?: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => (await api.post<{data: Order}>(`/orders/${orderId}/send`)).data.data,
    onSuccess: order => { qc.setQueryData(['orders', order.id], order); qc.invalidateQueries({ queryKey: ['orders'] }); },
  });
}
