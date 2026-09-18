import { useQuery } from '@tanstack/react-query';
import { api, getActiveBusinessId } from '../../lib/api';
import type { CatalogResponse } from './types';

export function useCatalog() {
  const businessId = getActiveBusinessId();

  return useQuery({
    queryKey: ['catalog', businessId],
    enabled: Boolean(businessId),
    queryFn: async () => {
      const response = await api.get<CatalogResponse>('/catalog');
      return response.data.data;
    },
  });
}
