import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  AlertTriangle,
  CheckCircle2,
  History,
  PackageCheck,
  Pencil,
  Plus,
  Search,
  ShoppingBag,
  Truck,
  X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '../../features/auth/AuthProvider';
import { api } from '../../lib/api';

type Supplier = {
  id: string;
  name: string;
  tax_number: string | null;
  contact_name: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  is_active: boolean;
  open_order_count: number;
};

type SupplierDraft = {
  id?: string;
  name: string;
  tax_number: string;
  contact_name: string;
  email: string;
  phone: string;
  address: string;
};

type ProductOption = {
  id: string;
  name: string;
  sku: string | null;
  unit_label: string | null;
};

type PurchasingOptions = {
  location_id: string;
  suppliers: Array<{ id: string; name: string; tax_number: string | null }>;
  products: ProductOption[];
};

type PurchaseOrderSummary = {
  id: string;
  number: string;
  supplier_id: string;
  supplier_name_snapshot: string;
  supplier_tax_number_snapshot: string | null;
  status: string;
  currency: string;
  total_cost: string;
  notes: string | null;
  ordered_at: string | null;
  cancelled_at: string | null;
  cancel_reason: string | null;
  created_at: string;
  line_count: number;
  quantity_ordered: string;
  quantity_received: string;
};

type PurchaseOrderItem = {
  id: string;
  product_id: string;
  product_name_snapshot: string;
  sku_snapshot: string | null;
  quantity_ordered: string;
  quantity_received: string;
  unit_cost: string;
  line_total: string;
};

type GoodsReceipt = {
  id: string;
  number: string;
  note: string | null;
  received_at: string;
};

type PurchaseOrderDetail = {
  order: PurchaseOrderSummary & { location_id: string; location_name: string };
  items: PurchaseOrderItem[];
  receipts: GoodsReceipt[];
};

type PurchaseOrderEvent = {
  id: string;
  event: string;
  previous_status: string | null;
  new_status: string;
  metadata: Record<string, unknown> | null;
  occurred_at: string;
  actor_name: string | null;
};

type PurchaseLineDraft = {
  product_id: string;
  quantity_ordered: string;
  unit_cost: string;
};

type PurchaseDraft = {
  supplier_id: string;
  notes: string;
  items: PurchaseLineDraft[];
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

function money(value: string | number, currency: string): string {
  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency,
    maximumFractionDigits: 2,
  }).format(Number(value));
}

function quantity(value: string | number): string {
  return new Intl.NumberFormat(undefined, {
    maximumFractionDigits: 4,
  }).format(Number(value));
}

function statusClass(status: string): string {
  if (status === 'received') return 'success';
  if (status === 'ordered' || status === 'partially_received') return 'warning';
  if (status === 'cancelled') return 'danger';
  return 'muted';
}

function Empty({ children }: { children: string }) {
  return <div className="management-empty">{children}</div>;
}

const blankSupplier: SupplierDraft = {
  name: '',
  tax_number: '',
  contact_name: '',
  email: '',
  phone: '',
  address: '',
};

const blankPurchase = (): PurchaseDraft => ({
  supplier_id: '',
  notes: '',
  items: [{ product_id: '', quantity_ordered: '1', unit_cost: '' }],
});

export function PurchasingPage() {
  const { activeBusiness, activeLocation, can } = useAuth();
  const qc = useQueryClient();
  const canManage = can('purchasing.manage');
  const canReceive = can('inventory.receive');

  const [section, setSection] = useState<'orders' | 'suppliers'>('orders');
  const [orderSearch, setOrderSearch] = useState('');
  const [orderStatus, setOrderStatus] = useState('all');
  const [supplierSearch, setSupplierSearch] = useState('');
  const [supplierStatus, setSupplierStatus] = useState('all');

  const [supplierEditor, setSupplierEditor] = useState<SupplierDraft | null>(null);
  const [supplierDisableTarget, setSupplierDisableTarget] = useState<Supplier | null>(null);
  const [purchaseEditor, setPurchaseEditor] = useState<PurchaseDraft | null>(null);
  const [detailOrderId, setDetailOrderId] = useState<string | null>(null);
  const [receiveOrderId, setReceiveOrderId] = useState<string | null>(null);
  const [receiveQuantities, setReceiveQuantities] = useState<Record<string, string>>({});
  const [receiveNote, setReceiveNote] = useState('');
  const [cancelTarget, setCancelTarget] = useState<PurchaseOrderSummary | null>(null);
  const [cancelReason, setCancelReason] = useState('');
  const [historyTarget, setHistoryTarget] = useState<PurchaseOrderSummary | null>(null);

  const suppliersQuery = useQuery({
    queryKey: ['purchasing-suppliers', activeBusiness?.id],
    enabled: Boolean(activeBusiness),
    queryFn: () => api.get<{ data: Supplier[] }>('/purchasing/suppliers').then(response => response.data.data),
  });

  const ordersQuery = useQuery({
    queryKey: ['purchase-orders', activeBusiness?.id, activeLocation?.id],
    enabled: Boolean(activeBusiness && activeLocation),
    queryFn: () => api
      .get<{ data: PurchaseOrderSummary[] }>(`/purchase-orders?location_id=${activeLocation!.id}`)
      .then(response => response.data.data),
  });

  const optionsQuery = useQuery({
    queryKey: ['purchasing-options', activeBusiness?.id, activeLocation?.id],
    enabled: Boolean(activeBusiness && activeLocation),
    queryFn: () => api
      .get<{ data: PurchasingOptions }>(`/purchasing/options?location_id=${activeLocation!.id}`)
      .then(response => response.data.data),
  });

  const detailQuery = useQuery({
    queryKey: ['purchase-order-detail', activeBusiness?.id, detailOrderId],
    enabled: Boolean(activeBusiness && detailOrderId),
    queryFn: () => api
      .get<{ data: PurchaseOrderDetail }>(`/purchase-orders/${detailOrderId}`)
      .then(response => response.data.data),
  });

  const receiveDetailQuery = useQuery({
    queryKey: ['purchase-order-detail', activeBusiness?.id, receiveOrderId],
    enabled: Boolean(activeBusiness && receiveOrderId),
    queryFn: () => api
      .get<{ data: PurchaseOrderDetail }>(`/purchase-orders/${receiveOrderId}`)
      .then(response => response.data.data),
  });

  const historyQuery = useQuery({
    queryKey: ['purchase-order-events', activeBusiness?.id, historyTarget?.id],
    enabled: Boolean(activeBusiness && historyTarget),
    queryFn: () => api
      .get<{ data: PurchaseOrderEvent[] }>(`/purchase-orders/${historyTarget!.id}/events`)
      .then(response => response.data.data),
  });

  useEffect(() => {
    if (!receiveDetailQuery.data) return;
    const next: Record<string, string> = {};
    receiveDetailQuery.data.items.forEach(item => {
      const remaining = Number(item.quantity_ordered) - Number(item.quantity_received);
      if (remaining > 0) next[item.id] = '';
    });
    setReceiveQuantities(next);
    setReceiveNote('');
  }, [receiveDetailQuery.data]);

  const refreshPurchasing = async () => {
    await Promise.all([
      qc.invalidateQueries({ queryKey: ['purchasing-suppliers', activeBusiness?.id] }),
      qc.invalidateQueries({ queryKey: ['purchase-orders', activeBusiness?.id, activeLocation?.id] }),
      qc.invalidateQueries({ queryKey: ['purchasing-options', activeBusiness?.id, activeLocation?.id] }),
    ]);
  };

  const saveSupplier = useMutation({
    mutationFn: (draft: SupplierDraft) => api.post('/purchasing/suppliers', {
      id: draft.id,
      name: draft.name.trim(),
      tax_number: draft.tax_number.trim() || null,
      contact_name: draft.contact_name.trim() || null,
      email: draft.email.trim() || null,
      phone: draft.phone.trim() || null,
      address: draft.address.trim() || null,
    }),
    onSuccess: async () => {
      setSupplierEditor(null);
      await refreshPurchasing();
    },
  });

  const toggleSupplier = useMutation({
    mutationFn: (supplier: Supplier) => api.patch(`/purchasing/suppliers/${supplier.id}/status`, {
      is_active: !supplier.is_active,
    }),
    onSuccess: async () => {
      setSupplierDisableTarget(null);
      await refreshPurchasing();
    },
  });

  const createPurchase = useMutation({
    mutationFn: (draft: PurchaseDraft) => api.post('/purchase-orders', {
      location_id: activeLocation!.id,
      supplier_id: draft.supplier_id,
      notes: draft.notes.trim() || null,
      items: draft.items.map(item => ({
        product_id: item.product_id,
        quantity_ordered: item.quantity_ordered,
        unit_cost: item.unit_cost,
      })),
    }),
    onSuccess: async () => {
      setPurchaseEditor(null);
      await refreshPurchasing();
    },
  });

  const placePurchase = useMutation({
    mutationFn: (order: PurchaseOrderSummary) => api.post(`/purchase-orders/${order.id}/place`),
    onSuccess: async (_response, order) => {
      await refreshPurchasing();
      await qc.invalidateQueries({ queryKey: ['purchase-order-detail', activeBusiness?.id, order.id] });
      await qc.invalidateQueries({ queryKey: ['purchase-order-events', activeBusiness?.id, order.id] });
    },
  });

  const cancelPurchase = useMutation({
    mutationFn: ({ order, reason }: { order: PurchaseOrderSummary; reason: string }) =>
      api.post(`/purchase-orders/${order.id}/cancel`, { reason }),
    onSuccess: async (_response, variables) => {
      setCancelTarget(null);
      setCancelReason('');
      await refreshPurchasing();
      await qc.invalidateQueries({ queryKey: ['purchase-order-detail', activeBusiness?.id, variables.order.id] });
      await qc.invalidateQueries({ queryKey: ['purchase-order-events', activeBusiness?.id, variables.order.id] });
    },
  });

  const receivePurchase = useMutation({
    mutationFn: ({ orderId, items, note }: {
      orderId: string;
      items: Array<{ purchase_order_item_id: string; quantity_received: string }>;
      note: string;
    }) => api.post(`/purchase-orders/${orderId}/receipts`, {
      note: note.trim() || null,
      items,
    }),
    onSuccess: async (_response, variables) => {
      setReceiveOrderId(null);
      setReceiveQuantities({});
      setReceiveNote('');
      await refreshPurchasing();
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['purchase-order-detail', activeBusiness?.id, variables.orderId] }),
        qc.invalidateQueries({ queryKey: ['purchase-order-events', activeBusiness?.id, variables.orderId] }),
        qc.invalidateQueries({ queryKey: ['inventory'] }),
      ]);
    },
  });

  const suppliers = suppliersQuery.data ?? [];
  const orders = ordersQuery.data ?? [];
  const options = optionsQuery.data;
  const currency = activeBusiness?.currency ?? 'EUR';

  const filteredSuppliers = useMemo(() => {
    const term = supplierSearch.trim().toLowerCase();
    return suppliers.filter(supplier =>
      (supplierStatus === 'all' || (supplierStatus === 'active' ? supplier.is_active : !supplier.is_active))
      && (!term || [
        supplier.name,
        supplier.tax_number ?? '',
        supplier.contact_name ?? '',
        supplier.email ?? '',
      ].some(value => value.toLowerCase().includes(term)))
    );
  }, [suppliers, supplierSearch, supplierStatus]);

  const filteredOrders = useMemo(() => {
    const term = orderSearch.trim().toLowerCase();
    return orders.filter(order =>
      (orderStatus === 'all' || order.status === orderStatus)
      && (!term || [
        order.number,
        order.supplier_name_snapshot,
        order.status,
      ].some(value => value.toLowerCase().includes(term)))
    );
  }, [orders, orderSearch, orderStatus]);

  const openOrders = orders.filter(order => ['draft', 'ordered', 'partially_received'].includes(order.status));
  const outstandingQuantity = openOrders.reduce(
    (sum, order) => sum + Math.max(0, Number(order.quantity_ordered) - Number(order.quantity_received)),
    0,
  );
  const openValue = openOrders.reduce((sum, order) => sum + Number(order.total_cost), 0);

  const purchaseValid = Boolean(
    purchaseEditor
    && purchaseEditor.supplier_id
    && purchaseEditor.items.length > 0
    && purchaseEditor.items.every(item =>
      item.product_id
      && Number(item.quantity_ordered) > 0
      && item.unit_cost !== ''
      && Number(item.unit_cost) >= 0
    )
    && new Set(purchaseEditor.items.map(item => item.product_id)).size === purchaseEditor.items.length
  );

  const receiveItems = Object.entries(receiveQuantities)
    .filter(([, value]) => Number(value) > 0)
    .map(([purchase_order_item_id, quantity_received]) => ({
      purchase_order_item_id,
      quantity_received,
    }));

  if (!activeLocation) {
    return <div className="management-empty">Select an active location before managing purchasing.</div>;
  }

  return <main className="management-page purchasing-page">
    <div className="page-heading">
      <div>
        <span className="eyebrow">PROCUREMENT</span>
        <h1>Purchasing & Receiving</h1>
        <p>Supplier management, immutable purchase orders, partial receiving and inventory cost history for {activeLocation.name}.</p>
      </div>
      {section === 'orders' && canManage && (
        <button
          type="button"
          className="primary-button"
          disabled={!options || options.suppliers.length === 0 || options.products.length === 0}
          onClick={() => {
            createPurchase.reset();
            setPurchaseEditor(blankPurchase());
          }}
        >
          <Plus size={16} />
          New purchase order
        </button>
      )}
      {section === 'suppliers' && canManage && (
        <button
          type="button"
          className="primary-button"
          onClick={() => {
            saveSupplier.reset();
            setSupplierEditor({ ...blankSupplier });
          }}
        >
          <Plus size={16} />
          Add supplier
        </button>
      )}
    </div>

    <div className="metric-grid management-metrics purchasing-metrics">
      <div className="metric-card"><span>Open purchase orders</span><strong>{openOrders.length}</strong><small>Draft, ordered or partially received</small></div>
      <div className="metric-card"><span>Outstanding quantity</span><strong>{quantity(outstandingQuantity)}</strong><small>Units still expected</small></div>
      <div className="metric-card"><span>Open order value</span><strong>{money(openValue, currency)}</strong><small>Committed PO cost</small></div>
      <div className="metric-card"><span>Active suppliers</span><strong>{suppliers.filter(supplier => supplier.is_active).length}</strong><small>{suppliers.length} total suppliers</small></div>
    </div>

    <div className="management-tabs" role="tablist" aria-label="Purchasing views">
      <button type="button" role="tab" aria-selected={section === 'orders'} className={section === 'orders' ? 'active' : ''} onClick={() => setSection('orders')}>
        <ShoppingBag size={15} /> Purchase orders
      </button>
      <button type="button" role="tab" aria-selected={section === 'suppliers'} className={section === 'suppliers' ? 'active' : ''} onClick={() => setSection('suppliers')}>
        <Truck size={15} /> Suppliers
      </button>
    </div>

    {section === 'orders' && (
      <section className="panel management-panel">
        <div className="panel-heading catalog-toolbar">
          <div>
            <h2>Purchase order workspace</h2>
            <p>Placed orders preserve supplier, product, quantity and unit-cost snapshots. Receipts write directly to the inventory ledger.</p>
          </div>
          <div className="toolbar-actions purchasing-toolbar">
            <label className="search-box compact-search">
              <Search size={16} />
              <input aria-label="Search purchase orders" value={orderSearch} onChange={event => setOrderSearch(event.target.value)} placeholder="Search PO, supplier or status…" />
              {orderSearch && <button type="button" className="search-clear" aria-label="Clear purchase order search" onClick={() => setOrderSearch('')}><X size={14} /></button>}
            </label>
            <select aria-label="Filter purchase order status" value={orderStatus} onChange={event => setOrderStatus(event.target.value)}>
              <option value="all">All statuses</option>
              <option value="draft">Draft</option>
              <option value="ordered">Ordered</option>
              <option value="partially_received">Partially received</option>
              <option value="received">Received</option>
              <option value="cancelled">Cancelled</option>
            </select>
            <span className="toolbar-result-count">{filteredOrders.length} of {orders.length}</span>
          </div>
        </div>

        {(ordersQuery.isError || optionsQuery.isError) && (
          <div className="management-state error">
            <AlertTriangle size={18} />
            <div>
              <strong>Purchasing data unavailable</strong>
              <span>{apiMessage(ordersQuery.error ?? optionsQuery.error)}</span>
            </div>
            <button type="button" className="secondary-button" onClick={() => {
              ordersQuery.refetch();
              optionsQuery.refetch();
            }}>Try again</button>
          </div>
        )}

        {ordersQuery.isLoading ? (
          <div className="management-state">Loading purchase orders…</div>
        ) : filteredOrders.length === 0 ? (
          <Empty>{orders.length ? 'No purchase orders match the current filters.' : 'No purchase orders yet. Create a draft to start procurement.'}</Empty>
        ) : (
          <div className="data-table-wrap">
            <table className="data-table purchasing-table">
              <thead>
                <tr><th>Purchase order</th><th>Supplier</th><th>Progress</th><th>Value</th><th>Status</th><th>Actions</th></tr>
              </thead>
              <tbody>
                {filteredOrders.map(order => {
                  const orderedQty = Number(order.quantity_ordered);
                  const receivedQty = Number(order.quantity_received);
                  const percent = orderedQty > 0 ? Math.min(100, (receivedQty / orderedQty) * 100) : 0;
                  return <tr key={order.id}>
                    <td><strong>{order.number}</strong><small className="cell-note">{order.line_count} line{order.line_count === 1 ? '' : 's'} · {new Date(order.created_at).toLocaleDateString()}</small></td>
                    <td><strong>{order.supplier_name_snapshot}</strong><small className="cell-note">{order.supplier_tax_number_snapshot ?? 'No tax number'}</small></td>
                    <td><div className="receiving-progress"><span><strong>{quantity(receivedQty)}</strong> / {quantity(orderedQty)}</span><div><i style={{ width: `${percent}%` }} /></div></div></td>
                    <td><strong>{money(order.total_cost, order.currency)}</strong></td>
                    <td><span className={`status-badge ${statusClass(order.status)}`}>{order.status.replaceAll('_', ' ')}</span></td>
                    <td><div className="inline-actions purchasing-actions">
                      <button type="button" className="secondary-button" onClick={() => setDetailOrderId(order.id)}>View</button>
                      {canManage && order.status === 'draft' && <button type="button" className="secondary-button" disabled={placePurchase.isPending} onClick={() => placePurchase.mutate(order)}><CheckCircle2 size={14} /> Place</button>}
                      {canReceive && ['ordered', 'partially_received'].includes(order.status) && <button type="button" className="primary-button compact-action" onClick={() => { receivePurchase.reset(); setReceiveOrderId(order.id); }}><PackageCheck size={14} /> Receive</button>}
                      <button type="button" className="secondary-button" onClick={() => setHistoryTarget(order)}><History size={14} /> History</button>
                      {canManage && ['draft', 'ordered'].includes(order.status) && <button type="button" className="secondary-button subtle-danger" onClick={() => { cancelPurchase.reset(); setCancelReason(''); setCancelTarget(order); }}>Cancel</button>}
                    </div></td>
                  </tr>;
                })}
              </tbody>
            </table>
          </div>
        )}

        {placePurchase.isError && <p className="error-state">{apiMessage(placePurchase.error)}</p>}
      </section>
    )}

    {section === 'suppliers' && (
      <section className="panel management-panel">
        <div className="panel-heading catalog-toolbar">
          <div><h2>Supplier directory</h2><p>Supplier records are deactivated rather than deleted so purchase history keeps a stable relationship.</p></div>
          <div className="toolbar-actions purchasing-toolbar">
            <label className="search-box compact-search">
              <Search size={16} />
              <input aria-label="Search suppliers" value={supplierSearch} onChange={event => setSupplierSearch(event.target.value)} placeholder="Search name, tax, contact or email…" />
              {supplierSearch && <button type="button" className="search-clear" aria-label="Clear supplier search" onClick={() => setSupplierSearch('')}><X size={14} /></button>}
            </label>
            <select aria-label="Filter supplier status" value={supplierStatus} onChange={event => setSupplierStatus(event.target.value)}>
              <option value="all">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option>
            </select>
            <span className="toolbar-result-count">{filteredSuppliers.length} of {suppliers.length}</span>
          </div>
        </div>

        {suppliersQuery.isLoading ? <div className="management-state">Loading suppliers…</div> : suppliersQuery.isError ? (
          <div className="management-state error"><AlertTriangle size={18} /><div><strong>Suppliers unavailable</strong><span>{apiMessage(suppliersQuery.error)}</span></div><button type="button" className="secondary-button" onClick={() => suppliersQuery.refetch()}>Try again</button></div>
        ) : filteredSuppliers.length === 0 ? <Empty>{suppliers.length ? 'No suppliers match the current filters.' : 'No suppliers yet.'}</Empty> : (
          <div className="data-table-wrap">
            <table className="data-table supplier-table">
              <thead><tr><th>Supplier</th><th>Contact</th><th>Open POs</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>{filteredSuppliers.map(supplier => <tr key={supplier.id}>
                <td><strong>{supplier.name}</strong><small className="cell-note">{supplier.tax_number ?? 'No tax number'}</small></td>
                <td><strong>{supplier.contact_name ?? '—'}</strong><small className="cell-note">{supplier.email ?? supplier.phone ?? 'No contact details'}</small></td>
                <td>{supplier.open_order_count > 0 ? <span className="status-badge warning">{supplier.open_order_count} open</span> : <span className="status-badge success">Clear</span>}</td>
                <td><span className={`status-badge ${supplier.is_active ? 'success' : 'muted'}`}>{supplier.is_active ? 'Active' : 'Inactive'}</span></td>
                <td>{canManage ? <div className="inline-actions">
                  <button type="button" className="secondary-button" onClick={() => { saveSupplier.reset(); setSupplierEditor({
                    id: supplier.id,
                    name: supplier.name,
                    tax_number: supplier.tax_number ?? '',
                    contact_name: supplier.contact_name ?? '',
                    email: supplier.email ?? '',
                    phone: supplier.phone ?? '',
                    address: supplier.address ?? '',
                  }); }}><Pencil size={14} /> Edit</button>
                  <button type="button" className={supplier.is_active ? 'secondary-button subtle-danger' : 'secondary-button'} disabled={toggleSupplier.isPending} onClick={() => supplier.is_active ? setSupplierDisableTarget(supplier) : toggleSupplier.mutate(supplier)}>
                    {supplier.is_active ? 'Disable' : 'Enable'}
                  </button>
                </div> : <span className="muted">View only</span>}</td>
              </tr>)}</tbody>
            </table>
          </div>
        )}
      </section>
    )}

    {supplierEditor && (
      <div className="modal-backdrop" role="presentation" onMouseDown={event => {
        if (event.target === event.currentTarget && !saveSupplier.isPending) setSupplierEditor(null);
      }}>
        <form className="modal-card management-modal purchasing-editor-modal" role="dialog" aria-modal="true" aria-label={supplierEditor.id ? 'Edit supplier' : 'Create supplier'} onSubmit={event => {
          event.preventDefault();
          if (supplierEditor.name.trim()) saveSupplier.mutate(supplierEditor);
        }}>
          <header><div><span className="eyebrow">SUPPLIER</span><h2>{supplierEditor.id ? 'Edit supplier' : 'Add supplier'}</h2><p>Contact information may change; purchase documents preserve supplier identity snapshots.</p></div><button type="button" className="icon-button" aria-label="Close supplier form" disabled={saveSupplier.isPending} onClick={() => setSupplierEditor(null)}><X size={18} /></button></header>
          <div className="form-grid">
            <label><span>Name</span><input autoFocus required maxLength={160} value={supplierEditor.name} onChange={event => setSupplierEditor({ ...supplierEditor, name: event.target.value })} /></label>
            <label><span>Tax number</span><input maxLength={80} value={supplierEditor.tax_number} onChange={event => setSupplierEditor({ ...supplierEditor, tax_number: event.target.value })} /></label>
            <label><span>Contact name</span><input maxLength={120} value={supplierEditor.contact_name} onChange={event => setSupplierEditor({ ...supplierEditor, contact_name: event.target.value })} /></label>
            <label><span>Email</span><input type="email" maxLength={190} value={supplierEditor.email} onChange={event => setSupplierEditor({ ...supplierEditor, email: event.target.value })} /></label>
            <label><span>Phone</span><input maxLength={80} value={supplierEditor.phone} onChange={event => setSupplierEditor({ ...supplierEditor, phone: event.target.value })} /></label>
            <label className="span-2"><span>Address</span><textarea maxLength={1000} value={supplierEditor.address} onChange={event => setSupplierEditor({ ...supplierEditor, address: event.target.value })} /></label>
          </div>
          {saveSupplier.isError && <p className="error-state">{apiMessage(saveSupplier.error)}</p>}
          <footer className="modal-actions"><button type="button" className="secondary-button" disabled={saveSupplier.isPending} onClick={() => setSupplierEditor(null)}>Cancel</button><button className="primary-button" disabled={saveSupplier.isPending || !supplierEditor.name.trim()}>{saveSupplier.isPending ? 'Saving…' : supplierEditor.id ? 'Save supplier' : 'Create supplier'}</button></footer>
        </form>
      </div>
    )}

    {supplierDisableTarget && (
      <div className="modal-backdrop" role="presentation" onMouseDown={event => {
        if (event.target === event.currentTarget && !toggleSupplier.isPending) setSupplierDisableTarget(null);
      }}>
        <div className="modal-card compact-confirmation" role="dialog" aria-modal="true" aria-label="Disable supplier">
          <header><div><span className="eyebrow">SUPPLIER AVAILABILITY</span><h2>Disable {supplierDisableTarget.name}?</h2><p>The supplier cannot be selected for new purchase orders. Existing purchase history remains unchanged.</p></div><button type="button" className="icon-button" aria-label="Close supplier confirmation" disabled={toggleSupplier.isPending} onClick={() => setSupplierDisableTarget(null)}><X size={18} /></button></header>
          {supplierDisableTarget.open_order_count > 0 && <div className="permission-banner warning"><AlertTriangle size={18} /><div><strong>{supplierDisableTarget.open_order_count} open purchase order{supplierDisableTarget.open_order_count === 1 ? '' : 's'} still depend on this supplier</strong><span>Complete or cancel them before disabling the supplier.</span></div></div>}
          {toggleSupplier.isError && <p className="error-state">{apiMessage(toggleSupplier.error)}</p>}
          <footer className="modal-actions"><button type="button" className="secondary-button" disabled={toggleSupplier.isPending} onClick={() => setSupplierDisableTarget(null)}>Keep active</button><button type="button" className="danger-action" disabled={toggleSupplier.isPending || supplierDisableTarget.open_order_count > 0} onClick={() => toggleSupplier.mutate(supplierDisableTarget)}>{toggleSupplier.isPending ? 'Disabling…' : 'Disable supplier'}</button></footer>
        </div>
      </div>
    )}

    {purchaseEditor && (
      <div className="modal-backdrop" role="presentation" onMouseDown={event => {
        if (event.target === event.currentTarget && !createPurchase.isPending) setPurchaseEditor(null);
      }}>
        <form className="modal-card management-modal purchase-order-editor" role="dialog" aria-modal="true" aria-label="Create purchase order" onSubmit={event => {
          event.preventDefault();
          if (purchaseValid) createPurchase.mutate(purchaseEditor);
        }}>
          <header><div><span className="eyebrow">PURCHASE ORDER DRAFT</span><h2>New purchase order</h2><p>Unit costs and quantities are authoritative procurement values. After placement the document becomes immutable.</p></div><button type="button" className="icon-button" aria-label="Close purchase order form" disabled={createPurchase.isPending} onClick={() => setPurchaseEditor(null)}><X size={18} /></button></header>
          <div className="form-grid">
            <label className="span-2"><span>Supplier</span><select required value={purchaseEditor.supplier_id} onChange={event => setPurchaseEditor({ ...purchaseEditor, supplier_id: event.target.value })}><option value="">Select supplier</option>{options?.suppliers.map(supplier => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}</select></label>
            <label className="span-2"><span>Internal notes</span><textarea maxLength={2000} value={purchaseEditor.notes} onChange={event => setPurchaseEditor({ ...purchaseEditor, notes: event.target.value })} placeholder="Optional procurement notes" /></label>
          </div>

          <div className="purchase-lines-header"><div><strong>Order lines</strong><small>Stock-tracked products only</small></div><button type="button" className="secondary-button" onClick={() => setPurchaseEditor(draft => draft ? { ...draft, items: [...draft.items, { product_id: '', quantity_ordered: '1', unit_cost: '' }] } : draft)}><Plus size={14} /> Add line</button></div>
          <div className="purchase-line-list">
            {purchaseEditor.items.map((line, index) => {
              const selectedIds = new Set(purchaseEditor.items.map((item, itemIndex) => itemIndex === index ? '' : item.product_id));
              return <div className="purchase-line-row" key={index}>
                <label><span>Product</span><select required value={line.product_id} onChange={event => setPurchaseEditor(draft => draft ? { ...draft, items: draft.items.map((item, itemIndex) => itemIndex === index ? { ...item, product_id: event.target.value } : item) } : draft)}><option value="">Select product</option>{options?.products.map(product => <option key={product.id} value={product.id} disabled={selectedIds.has(product.id)}>{product.name}{product.sku ? ` · ${product.sku}` : ''}</option>)}</select></label>
                <label><span>Quantity</span><input required type="number" min="0.0001" step="0.0001" inputMode="decimal" value={line.quantity_ordered} onChange={event => setPurchaseEditor(draft => draft ? { ...draft, items: draft.items.map((item, itemIndex) => itemIndex === index ? { ...item, quantity_ordered: event.target.value } : item) } : draft)} /></label>
                <label><span>Unit cost ({currency})</span><input required type="number" min="0" step="0.0001" inputMode="decimal" value={line.unit_cost} onChange={event => setPurchaseEditor(draft => draft ? { ...draft, items: draft.items.map((item, itemIndex) => itemIndex === index ? { ...item, unit_cost: event.target.value } : item) } : draft)} /></label>
                <div className="purchase-line-total"><span>Line total</span><strong>{money((Number(line.quantity_ordered) || 0) * (Number(line.unit_cost) || 0), currency)}</strong></div>
                <button type="button" className="icon-button" aria-label={`Remove purchase line ${index + 1}`} disabled={purchaseEditor.items.length === 1} onClick={() => setPurchaseEditor(draft => draft ? { ...draft, items: draft.items.filter((_, itemIndex) => itemIndex !== index) } : draft)}><X size={16} /></button>
              </div>;
            })}
          </div>
          <div className="purchase-total-summary"><span>Draft total</span><strong>{money(purchaseEditor.items.reduce((sum, line) => sum + (Number(line.quantity_ordered) || 0) * (Number(line.unit_cost) || 0), 0), currency)}</strong></div>
          {createPurchase.isError && <p className="error-state">{apiMessage(createPurchase.error)}</p>}
          <footer className="modal-actions"><button type="button" className="secondary-button" disabled={createPurchase.isPending} onClick={() => setPurchaseEditor(null)}>Cancel</button><button className="primary-button" disabled={createPurchase.isPending || !purchaseValid}>{createPurchase.isPending ? 'Creating draft…' : 'Create draft'}</button></footer>
        </form>
      </div>
    )}

    {detailOrderId && (
      <div className="modal-backdrop" role="presentation" onMouseDown={event => {
        if (event.target === event.currentTarget) setDetailOrderId(null);
      }}>
        <div className="modal-card management-modal purchase-detail-modal" role="dialog" aria-modal="true" aria-label="Purchase order detail">
          <header><div><span className="eyebrow">PURCHASE DOCUMENT</span><h2>{detailQuery.data?.order.number ?? 'Purchase order'}</h2><p>{detailQuery.data ? `${detailQuery.data.order.supplier_name_snapshot} · ${detailQuery.data.order.location_name}` : 'Loading immutable purchase snapshots…'}</p></div><button type="button" className="icon-button" aria-label="Close purchase order detail" onClick={() => setDetailOrderId(null)}><X size={18} /></button></header>
          {detailQuery.isLoading ? <div className="management-state">Loading purchase order…</div> : detailQuery.isError ? <p className="error-state">{apiMessage(detailQuery.error)}</p> : detailQuery.data && <>
            <div className="purchase-detail-summary"><div><span>Status</span><strong className={`status-badge ${statusClass(detailQuery.data.order.status)}`}>{detailQuery.data.order.status.replaceAll('_', ' ')}</strong></div><div><span>Total</span><strong>{money(detailQuery.data.order.total_cost, detailQuery.data.order.currency)}</strong></div><div><span>Supplier snapshot</span><strong>{detailQuery.data.order.supplier_name_snapshot}</strong></div></div>
            <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Product snapshot</th><th>Ordered</th><th>Received</th><th>Unit cost</th><th>Total</th></tr></thead><tbody>{detailQuery.data.items.map(item => <tr key={item.id}><td><strong>{item.product_name_snapshot}</strong><small className="cell-note">{item.sku_snapshot ?? 'No SKU'}</small></td><td>{quantity(item.quantity_ordered)}</td><td>{quantity(item.quantity_received)}</td><td>{money(item.unit_cost, detailQuery.data!.order.currency)}</td><td>{money(item.line_total, detailQuery.data!.order.currency)}</td></tr>)}</tbody></table></div>
            <div className="purchase-receipts"><h3>Goods receipts</h3>{detailQuery.data.receipts.length === 0 ? <Empty>No goods receipts posted yet.</Empty> : detailQuery.data.receipts.map(receipt => <div className="receipt-row" key={receipt.id}><span><strong>{receipt.number}</strong><small>{new Date(receipt.received_at).toLocaleString()}</small></span><small>{receipt.note ?? 'No receipt note'}</small></div>)}</div>
          </>}
          <footer className="modal-actions"><button type="button" className="primary-button" onClick={() => setDetailOrderId(null)}>Done</button></footer>
        </div>
      </div>
    )}

    {receiveOrderId && (
      <div className="modal-backdrop" role="presentation" onMouseDown={event => {
        if (event.target === event.currentTarget && !receivePurchase.isPending) setReceiveOrderId(null);
      }}>
        <div className="modal-card management-modal receive-order-modal" role="dialog" aria-modal="true" aria-label="Receive purchase order">
          <header><div><span className="eyebrow">GOODS RECEIPT</span><h2>{receiveDetailQuery.data?.order.number ?? 'Receive goods'}</h2><p>Enter only quantities physically received now. Over-receipt is blocked and inventory updates atomically.</p></div><button type="button" className="icon-button" aria-label="Close goods receipt" disabled={receivePurchase.isPending} onClick={() => setReceiveOrderId(null)}><X size={18} /></button></header>
          {receiveDetailQuery.isLoading ? <div className="management-state">Loading remaining quantities…</div> : receiveDetailQuery.isError ? <p className="error-state">{apiMessage(receiveDetailQuery.error)}</p> : receiveDetailQuery.data && <>
            <div className="receive-line-list">{receiveDetailQuery.data.items.map(item => {
              const remaining = Math.max(0, Number(item.quantity_ordered) - Number(item.quantity_received));
              return <label className="receive-line" key={item.id}><span><strong>{item.product_name_snapshot}</strong><small>Remaining {quantity(remaining)} · ordered {quantity(item.quantity_ordered)}</small></span><input type="number" min="0" max={remaining} step="0.0001" inputMode="decimal" disabled={remaining === 0 || receivePurchase.isPending} value={receiveQuantities[item.id] ?? ''} onChange={event => setReceiveQuantities(current => ({ ...current, [item.id]: event.target.value }))} placeholder="0" /></label>;
            })}</div>
            <label className="receipt-note-field"><span>Receipt note</span><textarea maxLength={1000} value={receiveNote} onChange={event => setReceiveNote(event.target.value)} placeholder="Optional delivery note / condition" /></label>
          </>}
          {receivePurchase.isError && <p className="error-state">{apiMessage(receivePurchase.error)}</p>}
          <footer className="modal-actions"><button type="button" className="secondary-button" disabled={receivePurchase.isPending} onClick={() => setReceiveOrderId(null)}>Cancel</button><button type="button" className="primary-button" disabled={receivePurchase.isPending || receiveItems.length === 0} onClick={() => receiveOrderId && receivePurchase.mutate({ orderId: receiveOrderId, items: receiveItems, note: receiveNote })}>{receivePurchase.isPending ? 'Posting receipt…' : 'Post goods receipt'}</button></footer>
        </div>
      </div>
    )}

    {cancelTarget && (
      <div className="modal-backdrop" role="presentation" onMouseDown={event => {
        if (event.target === event.currentTarget && !cancelPurchase.isPending) setCancelTarget(null);
      }}>
        <div className="modal-card compact-confirmation" role="dialog" aria-modal="true" aria-label="Cancel purchase order">
          <header><div><span className="eyebrow">CANCEL PURCHASE ORDER</span><h2>Cancel {cancelTarget.number}?</h2><p>Cancellation is permanent. Any purchase order with received goods is protected from cancellation.</p></div><button type="button" className="icon-button" aria-label="Close purchase cancellation" disabled={cancelPurchase.isPending} onClick={() => setCancelTarget(null)}><X size={18} /></button></header>
          <label className="receipt-note-field"><span>Reason</span><textarea autoFocus required minLength={3} maxLength={1000} value={cancelReason} onChange={event => setCancelReason(event.target.value)} placeholder="Why is this order being cancelled?" /></label>
          {cancelPurchase.isError && <p className="error-state">{apiMessage(cancelPurchase.error)}</p>}
          <footer className="modal-actions"><button type="button" className="secondary-button" disabled={cancelPurchase.isPending} onClick={() => setCancelTarget(null)}>Keep order</button><button type="button" className="danger-action" disabled={cancelPurchase.isPending || cancelReason.trim().length < 3} onClick={() => cancelPurchase.mutate({ order: cancelTarget, reason: cancelReason.trim() })}>{cancelPurchase.isPending ? 'Cancelling…' : 'Cancel purchase order'}</button></footer>
        </div>
      </div>
    )}

    {historyTarget && (
      <div className="modal-backdrop" role="presentation" onMouseDown={event => {
        if (event.target === event.currentTarget) setHistoryTarget(null);
      }}>
        <div className="modal-card management-modal invitation-history-modal purchase-history-modal" role="dialog" aria-modal="true" aria-label="Purchase order history">
          <header><div><span className="eyebrow">PURCHASE AUDIT</span><h2>{historyTarget.number}</h2><p>{historyTarget.supplier_name_snapshot} · immutable purchase lifecycle events</p></div><button type="button" className="icon-button" aria-label="Close purchase order history" onClick={() => setHistoryTarget(null)}><X size={18} /></button></header>
          {historyQuery.isLoading ? <div className="management-state">Loading purchase history…</div> : historyQuery.isError ? <p className="error-state">{apiMessage(historyQuery.error)}</p> : (
            <div className="invitation-timeline">{(historyQuery.data ?? []).map(event => <article className="invitation-timeline-event" key={event.id}><span className="timeline-dot" aria-hidden="true" /><div><header><strong>{event.event.replaceAll('_', ' ')}</strong><time>{new Date(event.occurred_at).toLocaleString()}</time></header><p>{event.previous_status ? `${event.previous_status} → ${event.new_status}` : `Created as ${event.new_status}`} · {event.actor_name ?? 'System'}</p>{typeof event.metadata?.goods_receipt_number === 'string' && <small>Receipt {event.metadata.goods_receipt_number}</small>}</div></article>)}</div>
          )}
          <footer className="modal-actions"><button type="button" className="primary-button" onClick={() => setHistoryTarget(null)}>Done</button></footer>
        </div>
      </div>
    )}
  </main>;
}
