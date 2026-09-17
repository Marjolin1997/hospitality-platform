import { useQuery } from '@tanstack/react-query';import { api,getActiveLocationId } from '../../lib/api';
export type VenueTable={id:string;location_id:string;venue_area_id:string;name:string;capacity:number|null;is_active:boolean};export type VenueArea={id:string;location_id:string;name:string;tables:VenueTable[]};
export function useVenue(locationId?:string){const active=locationId??getActiveLocationId()??undefined;return useQuery({queryKey:['venue',active],enabled:Boolean(active),queryFn:async()=>(await api.get<{data:VenueArea[]}>('/venue',{params:{location_id:active}})).data.data});}
