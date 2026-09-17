import { useQuery } from '@tanstack/react-query';
import { api } from '../../lib/api';

export type VenueTable = { id: string; location_id: string; venue_area_id: string; name: string; capacity: number | null; is_active: boolean };
export type VenueArea = { id: string; location_id: string; name: string; tables: VenueTable[] };

export function useVenue(locationId?: string) {
  return useQuery({
    queryKey: ['venue', locationId],
    queryFn: async () => (await api.get<{data: VenueArea[]}>('/venue', { params: locationId ? { location_id: locationId } : undefined })).data.data,
  });
}
