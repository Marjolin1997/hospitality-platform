import { useQuery } from '@tanstack/react-query';
import { api } from '../../lib/api';
import type { CatalogResponse } from './types';

export function useCatalog() {
  return useQuery({
    queryKey: ['catalog'],
    queryFn: async () => {
      const response = await api.get<CatalogResponse>('/catalog');
      return response.data.data;
    },
  });
}
