import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../lib/api';
import type { CashRegister, CashSession, CollectPaymentInput, Payment } from './types';

export function useCashRegisters() {
  return useQuery({ queryKey: ['cash-registers'], queryFn: async () => (await api.get<{data: CashRegister[]}>('/cash-registers')).data.data });
}

export function useCurrentCashSession() {
  return useQuery({ queryKey: ['cash-session', 'current'], queryFn: async () => (await api.get<{data: CashSession | null}>('/cash-sessions/current')).data.data });
}

export function useOpenCashSession() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: {cash_register_id: string; opening_cash: number}) => (await api.post<{data: CashSession}>('/cash-sessions', input)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['cash-session'] }),
  });
}

export function useCashMovement(sessionId?: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: {type: 'cash_in'|'cash_out'; amount: number; currency: string; reason: string}) => (await api.post(`/cash-sessions/${sessionId}/movements`, input)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['cash-session'] }),
  });
}

export function useCloseCashSession(sessionId?: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: {counted_cash: number; closing_note?: string}) => (await api.post<{data: CashSession}>(`/cash-sessions/${sessionId}/close`, input)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['cash-session'] }),
  });
}

export function useCollectPayment(orderId?: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: CollectPaymentInput) => (await api.post<{data: Payment}>(`/orders/${orderId}/payments`, input)).data.data,
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['orders'] }); qc.invalidateQueries({ queryKey: ['cash-session'] }); },
  });
}
