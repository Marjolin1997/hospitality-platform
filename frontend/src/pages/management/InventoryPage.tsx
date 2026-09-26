import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  AlertTriangle,
  ArrowLeftRight,
  Boxes,
  ClipboardCheck,
  History,
  PackagePlus,
  Plus,
  Search,
  Settings2,
  X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '../../features/auth/AuthProvider';
import { api } from '../../lib/api';

type Stock = {
  id: string;
  name: string;
  sku: string | null;
  quantity_on_hand: string;
  reorder_level: string;
};

type ReorderEvent = {
  id: string;
  action: string;
  previous_state: { product_id?: string; product_name?: string; reorder_level?: string } | null;
  new_state: { product_id?: string; product_name?: string; reorder_level?: string } | null;
  performed_at: string;
  performed_by_name: string;
};

type Movement = {
  id: string;
  product_id: string;
  product_name: string;
  sku: string | null;
  type: string;
  quantity_delta: string;
  reference_type: string | null;
  reference_id: string | null;
  note: string | null;
  occurred_at: string;
  created_by_user_id: number;
  created_by_name: string;
};

type TransferOption = {
  id: string;
  name: string;
  sku: string | null;
  unit_label: string | null;
  quantity_on_hand: string;
};

type TransferOptions = {
  source_location_id: string;
  destinations: Array<{ id: string; name: string }>;
  products: TransferOption[];
};

type TransferSummary = {
  id: string;
  number: string;
  source_location_id: string;
  source_location_name: string;
  destination_location_id: string;
  destination_location_name: string;
  status: string;
  note: string;
  posted_at: string;
  created_by_name: string;
  line_count: number;
  total_quantity: string;
};

type TransferDetail = {
  transfer: TransferSummary;
  items: Array<{
    id: string;
    product_id: string;
    product_name_snapshot: string;
    sku_snapshot: string | null;
    quantity: string;
  }>;
};

type CountSummary = {
  id: string;
  number: string;
  status: string;
  note: string | null;
  started_at: string;
  posted_at: string | null;
  cancelled_at: string | null;
  cancel_reason: string | null;
  created_by_name: string;
  posted_by_name: string | null;
  line_count: number;
  counted_line_count: number;
  variance_line_count: number;
};

type CountItem = {
  id: string;
  product_id: string;
  product_name_snapshot: string;
  sku_snapshot: string | null;
  expected_quantity: string;
  counted_quantity: string | null;
  variance_quantity: string | null;
};

type CountDetail = {
  count: CountSummary & { location_id: string; location_name: string };
  items: CountItem[];
};

type CountEvent = {
  id: string;
  event: string;
  previous_status: string | null;
  new_status: string;
  metadata: Record<string, unknown> | null;
  occurred_at: string;
  actor_name: string | null;
};

type TransferDraft = {
  idempotency_key: string;
  destination_location_id: string;
  note: string;
  items: Array<{ product_id: string; quantity: string }>;
};

function apiMessage(error: unknown): string {
  const response = (error as {
    response?: { data?: { errors?: Record<string, string[]>; message?: string } };
  })?.response;
  const errors = response?.data?.errors;

  if (errors) {
    const first = Object.values(errors).flat()[0];
    if (typeof first === 'string') return first;
  }

  return response?.data?.message ?? 'The request could not be completed.';
}

function qty(value: string | number): string {
  return new Intl.NumberFormat(undefined, { maximumFractionDigits: 4 }).format(Number(value));
}

function movementLabel(type: string): string {
  return type.replaceAll('_', ' ');
}

function movementClass(delta: string): string {
  const value = Number(delta);
  return value > 0 ? 'success' : value < 0 ? 'danger' : 'muted';
}

function countStatusClass(status: string): string {
  if (status === 'posted') return 'success';
  if (status === 'cancelled') return 'danger';
  return 'warning';
}

function Empty({ children }: { children: string }) {
  return <div className="management-empty">{children}</div>;
}

const newTransfer = (): TransferDraft => ({
  idempotency_key: crypto.randomUUID(),
  destination_location_id: '',
  note: '',
  items: [{ product_id: '', quantity: '1' }],
});

export function InventoryPage() {
  const { activeBusiness, activeLocation, can } = useAuth();
  const qc = useQueryClient();
  const canAdjust = can('inventory.adjust');
  const canTransfer = can('inventory.transfer');

  const [section, setSection] = useState<'stock' | 'ledger' | 'transfers' | 'counts'>('stock');
  const [stockSearch, setStockSearch] = useState('');
  const [stockFilter, setStockFilter] = useState('all');
  const [ledgerSearch, setLedgerSearch] = useState('');
  const [ledgerType, setLedgerType] = useState('all');
  const [transferSearch, setTransferSearch] = useState('');
  const [countSearch, setCountSearch] = useState('');
  const [countStatus, setCountStatus] = useState('all');

  const [adjusting, setAdjusting] = useState<Stock | null>(null);
  const [adjustmentDelta, setAdjustmentDelta] = useState('1');
  const [adjustmentNote, setAdjustmentNote] = useState('');
  const [adjustmentIdempotencyKey, setAdjustmentIdempotencyKey] = useState(() => crypto.randomUUID());
  const [reorderTarget, setReorderTarget] = useState<Stock | null>(null);
  const [reorderLevel, setReorderLevelValue] = useState('0');
  const [reorderHistoryTarget, setReorderHistoryTarget] = useState<Stock | null>(null);

  const [transferEditor, setTransferEditor] = useState<TransferDraft | null>(null);
  const [transferDetailId, setTransferDetailId] = useState<string | null>(null);

  const [countDetailId, setCountDetailId] = useState<string | null>(null);
  const [countValues, setCountValues] = useState<Record<string, string>>({});
  const [cancelCountTarget, setCancelCountTarget] = useState<CountSummary | null>(null);
  const [cancelCountReason, setCancelCountReason] = useState('');

  const stockQuery = useQuery({
    queryKey: ['inventory', activeBusiness?.id, activeLocation?.id],
    enabled: Boolean(activeBusiness && activeLocation),
    queryFn: () => api
      .get<{ data: Stock[] }>('/inventory', { params: { location_id: activeLocation!.id } })
      .then(response => response.data.data),
  });

  const reorderHistoryQuery = useQuery({
    queryKey: ['inventory-reorder-events', activeBusiness?.id, activeLocation?.id, reorderHistoryTarget?.id],
    enabled: Boolean(activeBusiness && activeLocation && reorderHistoryTarget),
    queryFn: () => api
      .get<{ data: ReorderEvent[] }>(`/inventory/products/${reorderHistoryTarget!.id}/reorder-level/events?location_id=${activeLocation!.id}`)
      .then(response => response.data.data),
  });

  const movementsQuery = useQuery({
    queryKey: ['inventory-movements', activeBusiness?.id, activeLocation?.id],
    enabled: Boolean(activeBusiness && activeLocation),
    queryFn: () => api
      .get<{ data: Movement[] }>(`/inventory/movements?location_id=${activeLocation!.id}`)
      .then(response => response.data.data),
  });

  const transferOptionsQuery = useQuery({
    queryKey: ['inventory-transfer-options', activeBusiness?.id, activeLocation?.id],
    enabled: Boolean(activeBusiness && activeLocation && canTransfer),
    queryFn: () => api
      .get<{ data: TransferOptions }>(`/inventory/transfer-options?location_id=${activeLocation!.id}`)
      .then(response => response.data.data),
  });

  const transfersQuery = useQuery({
    queryKey: ['inventory-transfers', activeBusiness?.id, activeLocation?.id],
    enabled: Boolean(activeBusiness && activeLocation),
    queryFn: () => api
      .get<{ data: TransferSummary[] }>(`/inventory/transfers?location_id=${activeLocation!.id}`)
      .then(response => response.data.data),
  });

  const transferDetailQuery = useQuery({
    queryKey: ['inventory-transfer-detail', activeBusiness?.id, transferDetailId],
    enabled: Boolean(activeBusiness && transferDetailId),
    queryFn: () => api
      .get<{ data: TransferDetail }>(`/inventory/transfers/${transferDetailId}`)
      .then(response => response.data.data),
  });

  const countsQuery = useQuery({
    queryKey: ['inventory-counts', activeBusiness?.id, activeLocation?.id],
    enabled: Boolean(activeBusiness && activeLocation),
    queryFn: () => api
      .get<{ data: CountSummary[] }>(`/inventory/counts?location_id=${activeLocation!.id}`)
      .then(response => response.data.data),
  });

  const countDetailQuery = useQuery({
    queryKey: ['inventory-count-detail', activeBusiness?.id, countDetailId],
    enabled: Boolean(activeBusiness && countDetailId),
    queryFn: () => api
      .get<{ data: CountDetail }>(`/inventory/counts/${countDetailId}`)
      .then(response => response.data.data),
  });

  const countEventsQuery = useQuery({
    queryKey: ['inventory-count-events', activeBusiness?.id, countDetailId],
    enabled: Boolean(activeBusiness && countDetailId),
    queryFn: () => api
      .get<{ data: CountEvent[] }>(`/inventory/counts/${countDetailId}/events`)
      .then(response => response.data.data),
  });

  useEffect(() => {
    if (!countDetailQuery.data) return;

    const values: Record<string, string> = {};
    countDetailQuery.data.items.forEach(item => {
      values[item.id] = item.counted_quantity ?? '';
    });
    setCountValues(values);
  }, [countDetailQuery.data]);

  const invalidateInventory = async () => {
    await Promise.all([
      qc.invalidateQueries({ queryKey: ['inventory', activeBusiness?.id, activeLocation?.id] }),
      qc.invalidateQueries({ queryKey: ['inventory-movements', activeBusiness?.id, activeLocation?.id] }),
      qc.invalidateQueries({ queryKey: ['inventory-transfer-options', activeBusiness?.id, activeLocation?.id] }),
      qc.invalidateQueries({ queryKey: ['inventory-transfers', activeBusiness?.id, activeLocation?.id] }),
      qc.invalidateQueries({ queryKey: ['inventory-counts', activeBusiness?.id, activeLocation?.id] }),
    ]);
  };

  const adjust = useMutation({
    mutationFn: ({ id, delta, note, idempotencyKey }: { id: string; delta: number; note: string; idempotencyKey: string }) =>
      api.post('/inventory/adjustments', {
        idempotency_key: idempotencyKey,
        location_id: activeLocation!.id,
        product_id: id,
        quantity_delta: delta,
        note,
      }),
    onSuccess: async () => {
      setAdjusting(null);
      setAdjustmentDelta('1');
      setAdjustmentNote('');
      setAdjustmentIdempotencyKey(crypto.randomUUID());
      await invalidateInventory();
    },
  });

  const saveReorderLevel = useMutation({
    mutationFn: ({ productId, level }: { productId: string; level: string }) =>
      api.put(`/inventory/products/${productId}/reorder-level`, {
        location_id: activeLocation!.id,
        reorder_level: level,
      }),
    onSuccess: async (_response, variables) => {
      setReorderTarget(null);
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['inventory', activeBusiness?.id, activeLocation?.id] }),
        qc.invalidateQueries({ queryKey: ['inventory-reorder-events', activeBusiness?.id, activeLocation?.id, variables.productId] }),
      ]);
    },
  });

  const createTransfer = useMutation({
    mutationFn: (draft: TransferDraft) => api.post('/inventory/transfers', {
      idempotency_key: draft.idempotency_key,
      source_location_id: activeLocation!.id,
      destination_location_id: draft.destination_location_id,
      note: draft.note.trim(),
      items: draft.items.map(item => ({
        product_id: item.product_id,
        quantity: item.quantity,
      })),
    }),
    onSuccess: async () => {
      setTransferEditor(null);
      await invalidateInventory();
    },
  });

  const createCount = useMutation({
    mutationFn: () => api.post('/inventory/counts', {
      location_id: activeLocation!.id,
      note: null,
    }),
    onSuccess: async response => {
      const id = response.data.data.id as string;
      await invalidateInventory();
      setCountDetailId(id);
      setSection('counts');
    },
  });

  const saveCount = useMutation({
    mutationFn: ({ countId, items }: {
      countId: string;
      items: Array<{ inventory_count_item_id: string; counted_quantity: string | null }>;
    }) => api.put(`/inventory/counts/${countId}`, { items }),
    onSuccess: async (_response, variables) => {
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['inventory-count-detail', activeBusiness?.id, variables.countId] }),
        qc.invalidateQueries({ queryKey: ['inventory-count-events', activeBusiness?.id, variables.countId] }),
        qc.invalidateQueries({ queryKey: ['inventory-counts', activeBusiness?.id, activeLocation?.id] }),
      ]);
    },
  });

  const postCount = useMutation({
    mutationFn: (countId: string) => api.post(`/inventory/counts/${countId}/post`),
    onSuccess: async (_response, countId) => {
      await invalidateInventory();
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['inventory-count-detail', activeBusiness?.id, countId] }),
        qc.invalidateQueries({ queryKey: ['inventory-count-events', activeBusiness?.id, countId] }),
      ]);
    },
  });

  const cancelCount = useMutation({
    mutationFn: ({ countId, reason }: { countId: string; reason: string }) =>
      api.post(`/inventory/counts/${countId}/cancel`, { reason }),
    onSuccess: async (_response, variables) => {
      setCancelCountTarget(null);
      setCancelCountReason('');
      await invalidateInventory();
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['inventory-count-detail', activeBusiness?.id, variables.countId] }),
        qc.invalidateQueries({ queryKey: ['inventory-count-events', activeBusiness?.id, variables.countId] }),
      ]);
    },
  });

  const stocks = useMemo(() => stockQuery.data ?? [], [stockQuery.data]);
  const movements = useMemo(() => movementsQuery.data ?? [], [movementsQuery.data]);
  const transfers = useMemo(() => transfersQuery.data ?? [], [transfersQuery.data]);
  const counts = useMemo(() => countsQuery.data ?? [], [countsQuery.data]);
  const transferOptions = transferOptionsQuery.data;

  const lowStocks = stocks.filter(stock => Number(stock.quantity_on_hand) <= Number(stock.reorder_level));
  const outStocks = stocks.filter(stock => Number(stock.quantity_on_hand) <= 0);
  const draftCount = counts.find(count => count.status === 'draft') ?? null;

  const filteredStocks = useMemo(() => {
    const term = stockSearch.trim().toLowerCase();
    return stocks.filter(stock =>
      (stockFilter === 'all'
        || (stockFilter === 'low' && Number(stock.quantity_on_hand) <= Number(stock.reorder_level))
        || (stockFilter === 'out' && Number(stock.quantity_on_hand) <= 0)
        || (stockFilter === 'healthy' && Number(stock.quantity_on_hand) > Number(stock.reorder_level)))
      && (!term || `${stock.name} ${stock.sku ?? ''}`.toLowerCase().includes(term))
    );
  }, [stocks, stockSearch, stockFilter]);

  const filteredMovements = useMemo(() => {
    const term = ledgerSearch.trim().toLowerCase();
    return movements.filter(movement =>
      (ledgerType === 'all' || movement.type === ledgerType)
      && (!term || [
        movement.product_name,
        movement.sku ?? '',
        movement.type,
        movement.note ?? '',
        movement.reference_type ?? '',
      ].some(value => value.toLowerCase().includes(term)))
    );
  }, [movements, ledgerSearch, ledgerType]);

  const filteredTransfers = useMemo(() => {
    const term = transferSearch.trim().toLowerCase();
    return transfers.filter(transfer =>
      !term || [
        transfer.number,
        transfer.source_location_name,
        transfer.destination_location_name,
        transfer.note,
        transfer.created_by_name,
      ].some(value => value.toLowerCase().includes(term))
    );
  }, [transfers, transferSearch]);

  const filteredCounts = useMemo(() => {
    const term = countSearch.trim().toLowerCase();
    return counts.filter(count =>
      (countStatus === 'all' || count.status === countStatus)
      && (!term || [
        count.number,
        count.status,
        count.note ?? '',
        count.created_by_name,
      ].some(value => value.toLowerCase().includes(term)))
    );
  }, [counts, countSearch, countStatus]);

  const adjustmentDeltaNumber = Number(adjustmentDelta);
  const adjustmentValid = Boolean(adjusting)
    && Number.isFinite(adjustmentDeltaNumber)
    && adjustmentDeltaNumber !== 0
    && adjustmentNote.trim().length >= 3;
  const projected = adjusting
    ? Number(adjusting.quantity_on_hand) + (Number.isFinite(adjustmentDeltaNumber) ? adjustmentDeltaNumber : 0)
    : 0;

  const transferValid = Boolean(
    transferEditor
    && transferEditor.destination_location_id
    && transferEditor.note.trim().length >= 3
    && transferEditor.items.length > 0
    && transferEditor.items.every(item => {
      const product = transferOptions?.products.find(option => option.id === item.product_id);
      const amount = Number(item.quantity);
      return Boolean(product)
        && Number.isFinite(amount)
        && amount > 0
        && amount <= Number(product!.quantity_on_hand);
    })
    && new Set(transferEditor.items.map(item => item.product_id)).size === transferEditor.items.length
  );

  const countDetail = countDetailQuery.data;
  const countDraftItems = countDetail?.items.map(item => ({
    inventory_count_item_id: item.id,
    counted_quantity: countValues[item.id]?.trim() === '' ? null : countValues[item.id],
  })) ?? [];
  const allCounted = countDetail?.items.every(item => {
    const value = countValues[item.id];
    return value !== undefined && value.trim() !== '' && Number(value) >= 0;
  }) ?? false;

  if (!activeLocation) {
    return <div className="management-empty">Select an active location before managing inventory.</div>;
  }

  return <main className="management-page inventory-control-page">
    <div className="page-heading">
      <div>
        <span className="eyebrow">STOCK CONTROL</span>
        <h1>Inventory</h1>
        <p>Balances, immutable ledger movements, inter-location transfers and stock counts for {activeLocation.name}.</p>
      </div>
      <div className="page-heading-actions">
        {section === 'transfers' && canTransfer && (
          <button
            type="button"
            className="primary-button"
            disabled={!transferOptions || transferOptions.destinations.length === 0}
            onClick={() => {
              createTransfer.reset();
              setTransferEditor(newTransfer());
            }}
          >
            <ArrowLeftRight size={16} />
            New transfer
          </button>
        )}
        {section === 'counts' && canAdjust && (
          <button
            type="button"
            className="primary-button"
            disabled={createCount.isPending || Boolean(draftCount)}
            title={draftCount ? `Complete ${draftCount.number} before starting another count.` : undefined}
            onClick={() => createCount.mutate()}
          >
            <ClipboardCheck size={16} />
            {createCount.isPending ? 'Starting…' : 'Start stock count'}
          </button>
        )}
      </div>
    </div>

    <div className="metric-grid management-metrics inventory-control-metrics">
      <div className="metric-card"><span>Tracked items</span><strong>{stocks.length}</strong><small>Products in this location</small></div>
      <div className="metric-card"><span>Low stock</span><strong>{lowStocks.length}</strong><small>At or below reorder level</small></div>
      <div className="metric-card"><span>Out of stock</span><strong>{outStocks.length}</strong><small>Zero on hand</small></div>
      <div className="metric-card"><span>Open count</span><strong>{draftCount ? '1' : '0'}</strong><small>{draftCount?.number ?? 'No draft count'}</small></div>
    </div>

    <div className="management-tabs inventory-tabs" role="tablist" aria-label="Inventory views">
      <button type="button" role="tab" aria-selected={section === 'stock'} className={section === 'stock' ? 'active' : ''} onClick={() => setSection('stock')}><Boxes size={15} /> Stock</button>
      <button type="button" role="tab" aria-selected={section === 'ledger'} className={section === 'ledger' ? 'active' : ''} onClick={() => setSection('ledger')}><History size={15} /> Ledger</button>
      <button type="button" role="tab" aria-selected={section === 'transfers'} className={section === 'transfers' ? 'active' : ''} onClick={() => setSection('transfers')}><ArrowLeftRight size={15} /> Transfers</button>
      <button type="button" role="tab" aria-selected={section === 'counts'} className={section === 'counts' ? 'active' : ''} onClick={() => setSection('counts')}><ClipboardCheck size={15} /> Counts</button>
    </div>

    {section === 'stock' && (
      <section className="panel management-panel">
        <div className="panel-heading catalog-toolbar">
          <div><h2>Stock position</h2><p>Search inventory, isolate exceptions and use ledger adjustments instead of editing balances directly.</p></div>
          <div className="toolbar-actions inventory-toolbar">
            <label className="search-box compact-search"><Search size={16} /><input aria-label="Search inventory" value={stockSearch} onChange={event => setStockSearch(event.target.value)} placeholder="Search item or SKU…" />{stockSearch && <button type="button" className="search-clear" aria-label="Clear inventory search" onClick={() => setStockSearch('')}><X size={14} /></button>}</label>
            <select aria-label="Filter stock health" value={stockFilter} onChange={event => setStockFilter(event.target.value)}><option value="all">All stock</option><option value="low">Low stock</option><option value="out">Out of stock</option><option value="healthy">Healthy</option></select>
            <span className="toolbar-result-count">{filteredStocks.length} of {stocks.length}</span>
          </div>
        </div>

        {!canAdjust && <div className="permission-banner"><div><strong>Read-only stock balance</strong><span>Your role can inspect inventory but cannot create manual ledger adjustments or stock counts.</span></div></div>}
        {stockQuery.isLoading ? <div className="management-state">Loading stock position…</div> : stockQuery.isError ? (
          <div className="management-state error"><AlertTriangle size={18} /><div><strong>Stock unavailable</strong><span>{apiMessage(stockQuery.error)}</span></div><button type="button" className="secondary-button" onClick={() => stockQuery.refetch()}>Try again</button></div>
        ) : filteredStocks.length === 0 ? <Empty>{stocks.length ? 'No inventory items match the current filters.' : 'No stock-tracked products are configured.'}</Empty> : (
          <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Item</th><th>SKU</th><th>On hand</th><th>Reorder</th><th>Health</th><th>Action</th></tr></thead><tbody>{filteredStocks.map(stock => {
            const isLow = Number(stock.quantity_on_hand) <= Number(stock.reorder_level);
            const isOut = Number(stock.quantity_on_hand) <= 0;
            return <tr key={stock.id}><td><strong>{stock.name}</strong></td><td>{stock.sku ?? '—'}</td><td><strong>{qty(stock.quantity_on_hand)}</strong></td><td>{qty(stock.reorder_level)}</td><td><span className={`status-badge ${isOut ? 'danger' : isLow ? 'warning' : 'success'}`}>{isOut ? 'Out of stock' : isLow ? 'Low stock' : 'Healthy'}</span></td><td><div className="inline-actions inventory-stock-actions">{canAdjust && <button type="button" className="secondary-button" onClick={() => { adjust.reset(); setAdjusting(stock); setAdjustmentDelta('1'); setAdjustmentNote(''); setAdjustmentIdempotencyKey(crypto.randomUUID()); }}><PackagePlus size={15} /> Adjust</button>}{canAdjust && <button type="button" className="secondary-button" onClick={() => { saveReorderLevel.reset(); setReorderTarget(stock); setReorderLevelValue(stock.reorder_level); }}><Settings2 size={15} /> Reorder level</button>}<button type="button" className="secondary-button" onClick={() => setReorderHistoryTarget(stock)}><History size={15} /> History</button></div></td></tr>;
          })}</tbody></table></div>
        )}
      </section>
    )}

    {section === 'ledger' && (
      <section className="panel management-panel">
        <div className="panel-heading catalog-toolbar">
          <div><h2>Inventory ledger</h2><p>Every purchase receipt, transfer, count variance and manual adjustment remains traceable.</p></div>
          <div className="toolbar-actions inventory-ledger-toolbar">
            <label className="search-box compact-search"><Search size={16} /><input aria-label="Search inventory ledger" value={ledgerSearch} onChange={event => setLedgerSearch(event.target.value)} placeholder="Search product, note or reference…" />{ledgerSearch && <button type="button" className="search-clear" aria-label="Clear ledger search" onClick={() => setLedgerSearch('')}><X size={14} /></button>}</label>
            <select aria-label="Filter inventory movement type" value={ledgerType} onChange={event => setLedgerType(event.target.value)}><option value="all">All movements</option><option value="adjustment">Adjustment</option><option value="purchase_receipt">Purchase receipt</option><option value="transfer_in">Transfer in</option><option value="transfer_out">Transfer out</option><option value="stock_count">Stock count</option></select>
            <span className="toolbar-result-count">{filteredMovements.length} of {movements.length}</span>
          </div>
        </div>
        {movementsQuery.isLoading ? <div className="management-state">Loading inventory ledger…</div> : movementsQuery.isError ? (
          <div className="management-state error"><AlertTriangle size={18} /><div><strong>Ledger unavailable</strong><span>{apiMessage(movementsQuery.error)}</span></div><button type="button" className="secondary-button" onClick={() => movementsQuery.refetch()}>Try again</button></div>
        ) : filteredMovements.length === 0 ? <Empty>{movements.length ? 'No ledger movements match the current filters.' : 'No inventory movements recorded yet.'}</Empty> : (
          <div className="data-table-wrap"><table className="data-table inventory-ledger-table"><thead><tr><th>When</th><th>Product</th><th>Movement</th><th>Quantity</th><th>Reference</th><th>Operator</th></tr></thead><tbody>{filteredMovements.map(movement => <tr key={movement.id}><td>{new Date(movement.occurred_at).toLocaleString()}</td><td><strong>{movement.product_name}</strong><small className="cell-note">{movement.sku ?? 'No SKU'}</small></td><td><span className="status-badge">{movementLabel(movement.type)}</span><small className="cell-note">{movement.note ?? '—'}</small></td><td><strong className={`ledger-delta ${movementClass(movement.quantity_delta)}`}>{Number(movement.quantity_delta) > 0 ? '+' : ''}{qty(movement.quantity_delta)}</strong></td><td>{movement.reference_type ? <><strong>{movement.reference_type.replaceAll('_', ' ')}</strong><small className="cell-note mono-note">{movement.reference_id}</small></> : '—'}</td><td>{movement.created_by_name}</td></tr>)}</tbody></table></div>
        )}
      </section>
    )}

    {section === 'transfers' && (
      <section className="panel management-panel">
        <div className="panel-heading catalog-toolbar">
          <div><h2>Inter-location transfers</h2><p>Posted transfers are immutable and write matching transfer-out and transfer-in ledger entries.</p></div>
          <div className="toolbar-actions inventory-transfer-toolbar">
            <label className="search-box compact-search"><Search size={16} /><input aria-label="Search stock transfers" value={transferSearch} onChange={event => setTransferSearch(event.target.value)} placeholder="Search transfer, branch or note…" />{transferSearch && <button type="button" className="search-clear" aria-label="Clear transfer search" onClick={() => setTransferSearch('')}><X size={14} /></button>}</label>
            <span className="toolbar-result-count">{filteredTransfers.length} of {transfers.length}</span>
          </div>
        </div>

        {!canTransfer && <div className="permission-banner"><div><strong>Transfer history only</strong><span>Your role can inspect posted transfers but cannot move stock between locations.</span></div></div>}
        {canTransfer && transferOptions && transferOptions.destinations.length === 0 && <div className="permission-banner warning"><AlertTriangle size={18} /><div><strong>No destination branch available</strong><span>Create or reactivate another location before transferring stock.</span></div></div>}
        {transfersQuery.isLoading ? <div className="management-state">Loading transfers…</div> : transfersQuery.isError ? (
          <div className="management-state error"><AlertTriangle size={18} /><div><strong>Transfers unavailable</strong><span>{apiMessage(transfersQuery.error)}</span></div><button type="button" className="secondary-button" onClick={() => transfersQuery.refetch()}>Try again</button></div>
        ) : filteredTransfers.length === 0 ? <Empty>{transfers.length ? 'No transfers match this search.' : 'No stock transfers recorded for this location.'}</Empty> : (
          <div className="data-table-wrap"><table className="data-table inventory-transfer-table"><thead><tr><th>Transfer</th><th>Route</th><th>Quantity</th><th>Operator</th><th>Posted</th><th>Action</th></tr></thead><tbody>{filteredTransfers.map(transfer => {
            const outbound = transfer.source_location_id === activeLocation.id;
            return <tr key={transfer.id}><td><strong>{transfer.number}</strong><small className="cell-note">{transfer.line_count} line{transfer.line_count === 1 ? '' : 's'} · <span className={`status-badge ${outbound ? 'warning' : 'success'}`}>{outbound ? 'Outbound' : 'Inbound'}</span></small></td><td><strong>{transfer.source_location_name}</strong><small className="cell-note">→ {transfer.destination_location_name}</small></td><td>{qty(transfer.total_quantity)}</td><td>{transfer.created_by_name}</td><td>{new Date(transfer.posted_at).toLocaleString()}</td><td><button type="button" className="secondary-button" onClick={() => setTransferDetailId(transfer.id)}>View</button></td></tr>;
          })}</tbody></table></div>
        )}
        {createTransfer.isError && <p className="error-state">{apiMessage(createTransfer.error)}</p>}
      </section>
    )}

    {section === 'counts' && (
      <section className="panel management-panel">
        <div className="panel-heading catalog-toolbar">
          <div><h2>Stock counts</h2><p>Counts start from a locked snapshot. Posting is rejected if stock moved after the count began.</p></div>
          <div className="toolbar-actions inventory-count-toolbar">
            <label className="search-box compact-search"><Search size={16} /><input aria-label="Search stock counts" value={countSearch} onChange={event => setCountSearch(event.target.value)} placeholder="Search count, operator or note…" />{countSearch && <button type="button" className="search-clear" aria-label="Clear count search" onClick={() => setCountSearch('')}><X size={14} /></button>}</label>
            <select aria-label="Filter stock count status" value={countStatus} onChange={event => setCountStatus(event.target.value)}><option value="all">All statuses</option><option value="draft">Draft</option><option value="posted">Posted</option><option value="cancelled">Cancelled</option></select>
            <span className="toolbar-result-count">{filteredCounts.length} of {counts.length}</span>
          </div>
        </div>

        {!canAdjust && <div className="permission-banner"><div><strong>Count history only</strong><span>Your role can inspect counts but cannot start, edit, post or cancel them.</span></div></div>}
        {createCount.isError && <p className="error-state">{apiMessage(createCount.error)}</p>}
        {countsQuery.isLoading ? <div className="management-state">Loading stock counts…</div> : countsQuery.isError ? (
          <div className="management-state error"><AlertTriangle size={18} /><div><strong>Stock counts unavailable</strong><span>{apiMessage(countsQuery.error)}</span></div><button type="button" className="secondary-button" onClick={() => countsQuery.refetch()}>Try again</button></div>
        ) : filteredCounts.length === 0 ? <Empty>{counts.length ? 'No counts match the current filters.' : 'No stock counts recorded yet.'}</Empty> : (
          <div className="data-table-wrap"><table className="data-table inventory-count-table"><thead><tr><th>Count</th><th>Progress</th><th>Variances</th><th>Status</th><th>Started by</th><th>Action</th></tr></thead><tbody>{filteredCounts.map(count => <tr key={count.id}><td><strong>{count.number}</strong><small className="cell-note">{new Date(count.started_at).toLocaleString()}</small></td><td>{count.counted_line_count}/{count.line_count}</td><td>{count.status === 'posted' ? count.variance_line_count : '—'}</td><td><span className={`status-badge ${countStatusClass(count.status)}`}>{count.status}</span></td><td>{count.created_by_name}</td><td><div className="inline-actions"><button type="button" className="secondary-button" onClick={() => { saveCount.reset(); postCount.reset(); setCountDetailId(count.id); }}>{count.status === 'draft' && canAdjust ? 'Count items' : 'View'}</button>{count.status === 'draft' && canAdjust && <button type="button" className="secondary-button subtle-danger" onClick={() => { cancelCount.reset(); setCancelCountReason(''); setCancelCountTarget(count); }}>Cancel</button>}</div></td></tr>)}</tbody></table></div>
        )}
      </section>
    )}

    {adjusting && (
      <div className="modal-backdrop" role="presentation" onMouseDown={event => {
        if (event.target === event.currentTarget && !adjust.isPending) setAdjusting(null);
      }}>
        <form className="modal-card management-modal inventory-adjustment-modal" role="dialog" aria-modal="true" aria-label={`Adjust stock for ${adjusting.name}`} onSubmit={event => {
          event.preventDefault();
          if (adjustmentValid) adjust.mutate({ id: adjusting.id, delta: adjustmentDeltaNumber, note: adjustmentNote.trim(), idempotencyKey: adjustmentIdempotencyKey });
        }}>
          <header><div><span className="eyebrow">INVENTORY LEDGER</span><h2>Adjust {adjusting.name}</h2><p>Use a signed quantity and a reason. The balance is never edited silently.</p></div><button type="button" className="icon-button" aria-label="Close inventory adjustment" disabled={adjust.isPending} onClick={() => setAdjusting(null)}><X size={18} /></button></header>
          <div className="reconciliation-summary"><span>Current <strong>{qty(adjusting.quantity_on_hand)}</strong></span><span>Adjustment <strong>{Number.isFinite(adjustmentDeltaNumber) && adjustmentDeltaNumber > 0 ? '+' : ''}{Number.isFinite(adjustmentDeltaNumber) ? qty(adjustmentDeltaNumber) : '—'}</strong></span><span>Result <strong>{Number.isFinite(projected) ? qty(projected) : '—'}</strong></span></div>
          <div className="adjustment-presets" role="group" aria-label="Quick stock adjustments">{[-10, -1, 1, 10].map(value => <button type="button" className={Number(adjustmentDelta) === value ? 'active' : ''} key={value} onClick={() => setAdjustmentDelta(String(value))}>{value > 0 ? '+' : ''}{value}</button>)}</div>
          <div className="form-grid"><label><span>Quantity delta</span><input autoFocus required type="number" step="0.0001" inputMode="decimal" value={adjustmentDelta} onChange={event => setAdjustmentDelta(event.target.value)} /></label><label className="span-2"><span>Adjustment reason</span><textarea required minLength={3} maxLength={500} value={adjustmentNote} onChange={event => setAdjustmentNote(event.target.value)} placeholder="Waste, correction, verified discrepancy…" /></label></div>
          {projected < 0 && <p className="field-hint error">This adjustment would produce a negative balance.</p>}
          {adjust.isError && <p className="error-state">{apiMessage(adjust.error)}</p>}
          <footer className="modal-actions"><button type="button" className="secondary-button" disabled={adjust.isPending} onClick={() => setAdjusting(null)}>Cancel</button><button className="primary-button" disabled={!adjustmentValid || projected < 0 || adjust.isPending}>{adjust.isPending ? 'Applying…' : 'Apply adjustment'}</button></footer>
        </form>
      </div>
    )}

    {reorderTarget && (
      <div className="modal-backdrop" role="presentation" onMouseDown={event => {
        if (event.target === event.currentTarget && !saveReorderLevel.isPending) setReorderTarget(null);
      }}>
        <form className="modal-card compact-confirmation inventory-reorder-modal" role="dialog" aria-modal="true" aria-label={`Set reorder level for ${reorderTarget.name}`} onSubmit={event => {
          event.preventDefault();
          if (Number.isFinite(Number(reorderLevel)) && Number(reorderLevel) >= 0) {
            saveReorderLevel.mutate({ productId: reorderTarget.id, level: reorderLevel });
          }
        }}>
          <header><div><span className="eyebrow">LOW-STOCK THRESHOLD</span><h2>{reorderTarget.name}</h2><p>This changes alert configuration only. It never changes quantity on hand or creates an inventory movement.</p></div><button type="button" className="icon-button" aria-label="Close reorder level editor" disabled={saveReorderLevel.isPending} onClick={() => setReorderTarget(null)}><X size={18} /></button></header>
          <div className="reconciliation-summary"><span>On hand <strong>{qty(reorderTarget.quantity_on_hand)}</strong></span><span>Current threshold <strong>{qty(reorderTarget.reorder_level)}</strong></span></div>
          <label className="inventory-reason-field"><span>Reorder level</span><input autoFocus required type="number" min="0" step="0.0001" inputMode="decimal" value={reorderLevel} onChange={event => setReorderLevelValue(event.target.value)} /></label>
          {saveReorderLevel.isError && <p className="error-state">{apiMessage(saveReorderLevel.error)}</p>}
          <footer className="modal-actions"><button type="button" className="secondary-button" disabled={saveReorderLevel.isPending} onClick={() => setReorderTarget(null)}>Cancel</button><button className="primary-button" disabled={saveReorderLevel.isPending || reorderLevel === '' || !Number.isFinite(Number(reorderLevel)) || Number(reorderLevel) < 0}>{saveReorderLevel.isPending ? 'Saving…' : 'Save reorder level'}</button></footer>
        </form>
      </div>
    )}

    {reorderHistoryTarget && (
      <div className="modal-backdrop" role="presentation" onMouseDown={event => {
        if (event.target === event.currentTarget) setReorderHistoryTarget(null);
      }}>
        <div className="modal-card management-modal invitation-history-modal inventory-reorder-history-modal" role="dialog" aria-modal="true" aria-label="Reorder level history">
          <header><div><span className="eyebrow">INVENTORY CONFIGURATION AUDIT</span><h2>{reorderHistoryTarget.name}</h2><p>Immutable reorder-threshold changes for {activeLocation.name}. Quantity movements are recorded separately in the inventory ledger.</p></div><button type="button" className="icon-button" aria-label="Close reorder level history" onClick={() => setReorderHistoryTarget(null)}><X size={18} /></button></header>
          {reorderHistoryQuery.isLoading ? <div className="management-state">Loading threshold history…</div> : reorderHistoryQuery.isError ? <div className="management-state error"><AlertTriangle size={18} /><div><strong>Threshold history unavailable</strong><span>{apiMessage(reorderHistoryQuery.error)}</span></div><button type="button" className="secondary-button" onClick={() => reorderHistoryQuery.refetch()}>Try again</button></div> : (reorderHistoryQuery.data ?? []).length === 0 ? <Empty>No reorder-level changes have been recorded yet.</Empty> : <div className="invitation-timeline">{(reorderHistoryQuery.data ?? []).map(event => <article key={event.id} className="invitation-timeline-event"><span className="timeline-dot" aria-hidden="true" /><div><header><strong>{event.action.replaceAll('_', ' ')}</strong><time>{new Date(event.performed_at).toLocaleString()}</time></header><p>{qty(event.previous_state?.reorder_level ?? '0')} → {qty(event.new_state?.reorder_level ?? '0')} · {event.performed_by_name}</p></div></article>)}</div>}
          <footer className="modal-actions"><button type="button" className="primary-button" onClick={() => setReorderHistoryTarget(null)}>Done</button></footer>
        </div>
      </div>
    )}

    {transferEditor && (
      <div className="modal-backdrop" role="presentation" onMouseDown={event => {
        if (event.target === event.currentTarget && !createTransfer.isPending) setTransferEditor(null);
      }}>
        <form className="modal-card management-modal inventory-transfer-modal" role="dialog" aria-modal="true" aria-label="Create stock transfer" onSubmit={event => {
          event.preventDefault();
          if (transferValid) createTransfer.mutate(transferEditor);
        }}>
          <header><div><span className="eyebrow">POST STOCK TRANSFER</span><h2>Transfer from {activeLocation.name}</h2><p>Posting is immediate and immutable. Source and destination ledger entries are created in the same transaction.</p></div><button type="button" className="icon-button" aria-label="Close stock transfer" disabled={createTransfer.isPending} onClick={() => setTransferEditor(null)}><X size={18} /></button></header>
          <div className="form-grid"><label><span>Destination</span><select required value={transferEditor.destination_location_id} onChange={event => setTransferEditor({ ...transferEditor, destination_location_id: event.target.value })}><option value="">Select location</option>{transferOptions?.destinations.map(location => <option key={location.id} value={location.id}>{location.name}</option>)}</select></label><label className="span-2"><span>Transfer reason</span><textarea required minLength={3} maxLength={1000} value={transferEditor.note} onChange={event => setTransferEditor({ ...transferEditor, note: event.target.value })} placeholder="Replenish terrace bar, move closing stock…" /></label></div>
          <div className="transfer-lines-header"><strong>Products</strong><button type="button" className="secondary-button" onClick={() => setTransferEditor(draft => draft ? { ...draft, items: [...draft.items, { product_id: '', quantity: '1' }] } : draft)}><Plus size={14} /> Add line</button></div>
          <div className="transfer-line-list">{transferEditor.items.map((line, index) => {
            const selected = new Set(transferEditor.items.map((item, itemIndex) => itemIndex === index ? '' : item.product_id));
            const product = transferOptions?.products.find(option => option.id === line.product_id);
            return <div className="transfer-line" key={index}><label><span>Product</span><select required value={line.product_id} onChange={event => setTransferEditor(draft => draft ? { ...draft, items: draft.items.map((item, itemIndex) => itemIndex === index ? { ...item, product_id: event.target.value } : item) } : draft)}><option value="">Select product</option>{transferOptions?.products.map(option => <option key={option.id} value={option.id} disabled={selected.has(option.id) || Number(option.quantity_on_hand) <= 0}>{option.name}{option.sku ? ` · ${option.sku}` : ''} · on hand {qty(option.quantity_on_hand)}</option>)}</select></label><label><span>Quantity</span><input required type="number" min="0.0001" max={product?.quantity_on_hand} step="0.0001" inputMode="decimal" value={line.quantity} onChange={event => setTransferEditor(draft => draft ? { ...draft, items: draft.items.map((item, itemIndex) => itemIndex === index ? { ...item, quantity: event.target.value } : item) } : draft)} />{product && <small>Available {qty(product.quantity_on_hand)} {product.unit_label ?? ''}</small>}</label><button type="button" className="icon-button" aria-label={`Remove transfer line ${index + 1}`} disabled={transferEditor.items.length === 1} onClick={() => setTransferEditor(draft => draft ? { ...draft, items: draft.items.filter((_, itemIndex) => itemIndex !== index) } : draft)}><X size={16} /></button></div>;
          })}</div>
          {createTransfer.isError && <p className="error-state">{apiMessage(createTransfer.error)}</p>}
          <footer className="modal-actions"><button type="button" className="secondary-button" disabled={createTransfer.isPending} onClick={() => setTransferEditor(null)}>Cancel</button><button className="primary-button" disabled={!transferValid || createTransfer.isPending}>{createTransfer.isPending ? 'Posting transfer…' : 'Post transfer'}</button></footer>
        </form>
      </div>
    )}

    {transferDetailId && (
      <div className="modal-backdrop" role="presentation" onMouseDown={event => {
        if (event.target === event.currentTarget) setTransferDetailId(null);
      }}>
        <div className="modal-card management-modal inventory-transfer-detail-modal" role="dialog" aria-modal="true" aria-label="Stock transfer detail">
          <header><div><span className="eyebrow">IMMUTABLE TRANSFER</span><h2>{transferDetailQuery.data?.transfer.number ?? 'Stock transfer'}</h2><p>{transferDetailQuery.data ? `${transferDetailQuery.data.transfer.source_location_name} → ${transferDetailQuery.data.transfer.destination_location_name}` : 'Loading transfer snapshot…'}</p></div><button type="button" className="icon-button" aria-label="Close stock transfer detail" onClick={() => setTransferDetailId(null)}><X size={18} /></button></header>
          {transferDetailQuery.isLoading ? <div className="management-state">Loading transfer…</div> : transferDetailQuery.isError ? <p className="error-state">{apiMessage(transferDetailQuery.error)}</p> : transferDetailQuery.data && <>
            <div className="transfer-detail-summary"><div><span>Status</span><strong className="status-badge success">{transferDetailQuery.data.transfer.status}</strong></div><div><span>Operator</span><strong>{transferDetailQuery.data.transfer.created_by_name}</strong></div><div><span>Posted</span><strong>{new Date(transferDetailQuery.data.transfer.posted_at).toLocaleString()}</strong></div></div>
            <p className="transfer-note">{transferDetailQuery.data.transfer.note}</p>
            <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Product snapshot</th><th>SKU</th><th>Quantity</th></tr></thead><tbody>{transferDetailQuery.data.items.map(item => <tr key={item.id}><td><strong>{item.product_name_snapshot}</strong></td><td>{item.sku_snapshot ?? '—'}</td><td>{qty(item.quantity)}</td></tr>)}</tbody></table></div>
          </>}
          <footer className="modal-actions"><button type="button" className="primary-button" onClick={() => setTransferDetailId(null)}>Done</button></footer>
        </div>
      </div>
    )}

    {countDetailId && (
      <div className="modal-backdrop" role="presentation" onMouseDown={event => {
        if (event.target === event.currentTarget && !saveCount.isPending && !postCount.isPending) setCountDetailId(null);
      }}>
        <div className="modal-card management-modal inventory-count-detail-modal" role="dialog" aria-modal="true" aria-label="Stock count detail">
          <header><div><span className="eyebrow">STOCK COUNT</span><h2>{countDetail?.count.number ?? 'Stock count'}</h2><p>{countDetail ? `${countDetail.count.location_name} · ${countDetail.count.status}` : 'Loading stock count…'}</p></div><button type="button" className="icon-button" aria-label="Close stock count" disabled={saveCount.isPending || postCount.isPending} onClick={() => setCountDetailId(null)}><X size={18} /></button></header>
          {countDetailQuery.isLoading ? <div className="management-state">Loading count lines…</div> : countDetailQuery.isError ? <p className="error-state">{apiMessage(countDetailQuery.error)}</p> : countDetail && <>
            <div className="count-detail-summary"><div><span>Status</span><strong className={`status-badge ${countStatusClass(countDetail.count.status)}`}>{countDetail.count.status}</strong></div><div><span>Lines</span><strong>{countDetail.items.length}</strong></div><div><span>Started</span><strong>{new Date(countDetail.count.started_at).toLocaleString()}</strong></div></div>
            {countDetail.count.status === 'draft' && <div className="permission-banner warning"><AlertTriangle size={18} /><div><strong>Snapshot consistency is enforced</strong><span>If any stock movement occurs after this count started, posting will be blocked and the count must be restarted.</span></div></div>}
            <div className="data-table-wrap count-entry-wrap"><table className="data-table inventory-count-entry-table"><thead><tr><th>Product</th><th>Expected</th><th>Counted</th><th>Variance</th></tr></thead><tbody>{countDetail.items.map(item => {
              const countedValue = countValues[item.id] ?? '';
              const numericCounted = countedValue.trim() === '' ? null : Number(countedValue);
              const variance = numericCounted === null ? null : numericCounted - Number(item.expected_quantity);
              return <tr key={item.id}><td><strong>{item.product_name_snapshot}</strong><small className="cell-note">{item.sku_snapshot ?? 'No SKU'}</small></td><td>{qty(item.expected_quantity)}</td><td>{countDetail.count.status === 'draft' && canAdjust ? <input aria-label={`Counted quantity for ${item.product_name_snapshot}`} type="number" min="0" step="0.0001" inputMode="decimal" value={countedValue} onChange={event => setCountValues(current => ({ ...current, [item.id]: event.target.value }))} placeholder="Enter count" /> : item.counted_quantity === null ? '—' : qty(item.counted_quantity)}</td><td><strong className={variance !== null ? `ledger-delta ${variance > 0 ? 'success' : variance < 0 ? 'danger' : 'muted'}` : ''}>{countDetail.count.status === 'posted' && item.variance_quantity !== null ? `${Number(item.variance_quantity) > 0 ? '+' : ''}${qty(item.variance_quantity)}` : variance === null ? '—' : `${variance > 0 ? '+' : ''}${qty(variance)}`}</strong></td></tr>;
            })}</tbody></table></div>

            {(saveCount.isError || postCount.isError) && <p className="error-state">{apiMessage(saveCount.error ?? postCount.error)}</p>}

            <div className="count-history-section"><h3>Audit history</h3>{countEventsQuery.isLoading ? <div className="management-state">Loading count history…</div> : countEventsQuery.isError ? <p className="error-state">{apiMessage(countEventsQuery.error)}</p> : <div className="invitation-timeline">{(countEventsQuery.data ?? []).map(event => <article key={event.id} className="invitation-timeline-event"><span className="timeline-dot" aria-hidden="true" /><div><header><strong>{event.event.replaceAll('_', ' ')}</strong><time>{new Date(event.occurred_at).toLocaleString()}</time></header><p>{event.previous_status ? `${event.previous_status} → ${event.new_status}` : `Created as ${event.new_status}`} · {event.actor_name ?? 'System'}</p></div></article>)}</div>}</div>
          </>}
          <footer className="modal-actions">
            <button type="button" className="secondary-button" disabled={saveCount.isPending || postCount.isPending} onClick={() => setCountDetailId(null)}>Close</button>
            {countDetail?.count.status === 'draft' && canAdjust && <button type="button" className="secondary-button" disabled={saveCount.isPending || postCount.isPending} onClick={() => saveCount.mutate({ countId: countDetail.count.id, items: countDraftItems })}>{saveCount.isPending ? 'Saving…' : 'Save count draft'}</button>}
            {countDetail?.count.status === 'draft' && canAdjust && <button type="button" className="primary-button" disabled={!allCounted || saveCount.isPending || postCount.isPending} onClick={() => {
              saveCount.mutate(
                { countId: countDetail.count.id, items: countDraftItems },
                { onSuccess: () => postCount.mutate(countDetail.count.id) },
              );
            }}>{saveCount.isPending ? 'Saving…' : postCount.isPending ? 'Posting…' : 'Save & post count'}</button>}
          </footer>
        </div>
      </div>
    )}

    {cancelCountTarget && (
      <div className="modal-backdrop" role="presentation" onMouseDown={event => {
        if (event.target === event.currentTarget && !cancelCount.isPending) setCancelCountTarget(null);
      }}>
        <div className="modal-card compact-confirmation" role="dialog" aria-modal="true" aria-label="Cancel stock count">
          <header><div><span className="eyebrow">CANCEL STOCK COUNT</span><h2>Cancel {cancelCountTarget.number}?</h2><p>No inventory balance will change. The cancelled count remains in audit history.</p></div><button type="button" className="icon-button" aria-label="Close stock count cancellation" disabled={cancelCount.isPending} onClick={() => setCancelCountTarget(null)}><X size={18} /></button></header>
          <label className="inventory-reason-field"><span>Reason</span><textarea autoFocus required minLength={3} maxLength={1000} value={cancelCountReason} onChange={event => setCancelCountReason(event.target.value)} placeholder="Why is this count being cancelled?" /></label>
          {cancelCount.isError && <p className="error-state">{apiMessage(cancelCount.error)}</p>}
          <footer className="modal-actions"><button type="button" className="secondary-button" disabled={cancelCount.isPending} onClick={() => setCancelCountTarget(null)}>Keep count</button><button type="button" className="danger-action" disabled={cancelCount.isPending || cancelCountReason.trim().length < 3} onClick={() => cancelCount.mutate({ countId: cancelCountTarget.id, reason: cancelCountReason.trim() })}>{cancelCount.isPending ? 'Cancelling…' : 'Cancel count'}</button></footer>
        </div>
      </div>
    )}
  </main>;
}
