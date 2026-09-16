import { useMemo, useState } from 'react';
import type { Product } from '../catalog/types';

type CartLine = { product: Product; quantity: number };

export function useOrderCart() {
  const [lines, setLines] = useState<CartLine[]>([]);

  const add = (product: Product) => setLines((current) => {
    const existing = current.find((line) => line.product.id === product.id);
    return existing
      ? current.map((line) => line.product.id === product.id ? { ...line, quantity: line.quantity + 1 } : line)
      : [...current, { product, quantity: 1 }];
  });

  const changeQuantity = (productId: string, delta: number) => setLines((current) => current
    .map((line) => line.product.id === productId ? { ...line, quantity: line.quantity + delta } : line)
    .filter((line) => line.quantity > 0));

  const clear = () => setLines([]);

  const total = useMemo(() => lines.reduce(
    (sum, line) => sum + Number(line.product.sale_price) * line.quantity,
    0,
  ), [lines]);

  return { lines, add, changeQuantity, clear, total };
}
