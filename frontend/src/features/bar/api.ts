import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../lib/api';

export type BarQueueItem = { id:string; order_id:string; product_name_snapshot:string; quantity:string; note:string|null; preparation_station:string|null; preparation_status:'sent'|'preparing'|'ready'; sent_at:string|null; order:{id:string;number:string;venue_table_id:string|null} };
export function useBarQueue(station?:string) { return useQuery({ queryKey:['bar-queue',station], refetchInterval:5000, queryFn:async()=> (await api.get<{data:BarQueueItem[]}>('/bar-queue',{params:station?{station}:undefined})).data.data }); }
export function useTransitionBarItem() { const qc=useQueryClient(); return useMutation({ mutationFn:async({id,status}:{id:string;status:'preparing'|'ready'|'served'}) => (await api.patch(`/bar-queue/${id}/status`,{status})).data.data, onSuccess:()=>qc.invalidateQueries({queryKey:['bar-queue']}) }); }
