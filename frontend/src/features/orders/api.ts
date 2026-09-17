import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../lib/api';
import type { Order, OrderItem } from './types';

export function useOrder(orderId?: string) {
  return useQuery({ queryKey: ['orders', orderId], enabled: Boolean(orderId), queryFn: async () => (await api.get<{ data: Order }>(`/orders/${orderId}`)).data.data });
}

function useOrderMutation<T>(orderId: string | undefined, request: (id: string, payload: T) => Promise<Order>) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: T) => request(orderId!, payload),
    onSuccess: order => {
      qc.setQueryData(['orders', order.id], order);
      qc.invalidateQueries({ queryKey: ['orders'] });
    },
  });
}

export function useSendOrder(orderId?: string) { return useOrderMutation<void>(orderId, async id => (await api.post<{ data: Order }>(`/orders/${id}/send`)).data.data); }
export function useAddOrderItem(orderId?: string) { return useOrderMutation<{ product_id: string; quantity: string; note?: string | null }>(orderId, async (id, p) => (await api.post<{ data: Order }>(`/orders/${id}/items`, p)).data.data); }
export function useUpdateOrderItem(orderId?: string) { return useOrderMutation<{ item_id: string; quantity?: string; note?: string | null }>(orderId, async (_id, p) => (await api.patch<{ data: Order }>(`/order-items/${p.item_id}`, { quantity: p.quantity, note: p.note })).data.data); }
export function useRemoveOrderItem(orderId?: string) { return useOrderMutation<{ item_id: string; reason: string }>(orderId, async (_id, p) => (await api.delete<{ data: Order }>(`/order-items/${p.item_id}`, { data: { reason: p.reason } })).data.data); }
export function useOverrideOrderItemPrice(orderId?: string) { return useOrderMutation<{ item_id: string; unit_price: string; reason: string }>(orderId, async (_id, p) => (await api.put<{ data: Order }>(`/order-items/${p.item_id}/price`, { unit_price: p.unit_price, reason: p.reason })).data.data); }
export function useApplyOrderDiscount(orderId?: string) { return useOrderMutation<{ amount: string; reason: string }>(orderId, async (id, p) => (await api.put<{ data: Order }>(`/orders/${id}/discount`, p)).data.data); }
export function useMoveOrderTable(orderId?: string) { return useOrderMutation<{ venue_table_id: string; reason: string }>(orderId, async (id, p) => (await api.post<{ data: Order }>(`/orders/${id}/move-table`, p)).data.data); }
export function useCancelOrder(orderId?: string) { return useOrderMutation<{ reason: string }>(orderId, async (id, p) => (await api.post<{ data: Order }>(`/orders/${id}/cancel`, p)).data.data); }

export function useSplitOrder(orderId?: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (p: { idempotency_key: string; item_ids: string[]; venue_table_id?: string | null; reason: string }) => (await api.post<{ data: { source: Order; destination: Order } }>(`/orders/${orderId}/split`, p)).data.data,
    onSuccess: data => {
      qc.setQueryData(['orders', data.source.id], data.source);
      qc.setQueryData(['orders', data.destination.id], data.destination);
      qc.invalidateQueries({ queryKey: ['orders'] });
    },
  });
}
export function useMergeOrder(orderId?: string) { return useOrderMutation<{ idempotency_key: string; source_order_id: string; reason: string }>(orderId, async (id, p) => (await api.post<{ data: Order }>(`/orders/${id}/merge`, p)).data.data); }

function invalidateItemOrder(qc: ReturnType<typeof useQueryClient>, item: OrderItem) {
  qc.invalidateQueries({ queryKey: ['orders', item.order_id] });
  qc.invalidateQueries({ queryKey: ['orders'] });
}
export function useCancelOrderItem() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: async (p: { itemId: string; reason: string }) => (await api.post<{ data: OrderItem }>(`/order-items/${p.itemId}/cancel`, { reason: p.reason })).data.data, onSuccess: item => invalidateItemOrder(qc, item) });
}
export function useTransitionOrderItem() {
  const qc = useQueryClient();
  return useMutation({ mutationFn: async (p: { itemId: string; status: 'preparing' | 'ready' | 'served' }) => (await api.patch<{ data: OrderItem }>(`/order-items/${p.itemId}/preparation`, { status: p.status })).data.data, onSuccess: item => invalidateItemOrder(qc, item) });
}
