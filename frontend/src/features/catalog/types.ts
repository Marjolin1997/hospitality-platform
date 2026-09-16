export type Product = {
  id: string;
  name: string;
  sku: string | null;
  barcode: string | null;
  sale_price: string;
  tax_rate: string;
  preparation_station: string | null;
  tracks_stock: boolean;
  is_active: boolean;
};

export type ProductCategory = {
  id: string;
  name: string;
  color: string | null;
  sort_order: number;
  products: Product[];
};

export type CatalogResponse = { data: ProductCategory[] };
