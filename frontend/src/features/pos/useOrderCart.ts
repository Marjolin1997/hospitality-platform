import { useMemo, useState } from 'react';
import type { Product } from '../catalog/types';
import type { CreateOrderItemInput } from '../orders/types';

export type CartLine = { product: Product; quantity: number; note: string };

export function useOrderCart() {
  const [lines, setLines] = useState<CartLine[]>([]);

  const add = (product: Product) => setLines((current) => {
    const existing = current.find((line) => line.product.id === product.id);
    return existing
      ? current.map((line) => line.product.id === product.id ? { ...line, quantity: line.quantity + 1 } : line)
      : [...current, { product, quantity: 1, note: '' }];
  });

  const changeQuantity = (productId: string, delta: number) => setLines((current) => current
    .map((line) => line.product.id === productId ? { ...line, quantity: line.quantity + delta } : line)
    .filter((line) => line.quantity > 0));

  const setNote = (productId: string, note: string) => setLines((current) => current.map(
    (line) => line.product.id === productId ? { ...line, note } : line,
  ));

  const clear = () => setLines([]);

  const total = useMemo(() => lines.reduce(
    (sum, line) => sum + Number(line.product.sale_price) * line.quantity,
    0,
  ), [lines]);

  const toOrderItems = (): CreateOrderItemInput[] => lines.map((line) => ({
    product_id: line.product.id,
    quantity: line.quantity,
    ...(line.note.trim() ? { note: line.note.trim() } : {}),
  }));

  return { lines, add, changeQuantity, setNote, clear, total, toOrderItems };
}
