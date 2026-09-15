/**
 * Supplier adapters.
 *
 * Each distributor gets an adapter that turns an order line into either a code
 * the customer can redeem or a confirmed top-up. The rest of the system talks
 * only to this interface, so swapping or adding a distributor is one file.
 *
 * No real supplier is wired up yet — `stub` stands in until you tell me which
 * distributor you use and I can implement its API. See DEPLOYMENT.md.
 */

export interface FulfilRequest {
  /** Order line id, stable across retries — pass it as the supplier's own
   *  idempotency key wherever the API supports one. */
  lineId: string;
  orderReference: string;
  supplierSku: string;
  quantity: number;
  /** Customer-supplied values: player_id, delivery_email, and so on. */
  fields: Record<string, string>;
}

export type FulfilResult =
  /** Done. `payload` is what the customer should see. */
  | { status: "delivered"; payload: string; supplierRef?: string }
  /** Accepted but not finished; poll or wait for the supplier's callback. */
  | { status: "pending"; supplierRef: string }
  /** Permanently rejected. The order is refunded to the wallet. */
  | { status: "failed"; reason: string; retryable: false }
  /** Transient. Worth another attempt. */
  | { status: "failed"; reason: string; retryable: true };

export interface SupplierAdapter {
  id: string;
  fulfil(request: FulfilRequest, env: Env): Promise<FulfilResult>;
}

/**
 * Placeholder adapter. It never pretends to have delivered anything: it hands
 * the line to staff instead, so nothing is ever marked delivered without a real
 * code behind it.
 */
const stubAdapter: SupplierAdapter = {
  id: "stub",
  async fulfil() {
    return {
      status: "failed",
      reason: "supplier_not_configured",
      retryable: false,
    };
  },
};

const adapters = new Map<string, SupplierAdapter>([[stubAdapter.id, stubAdapter]]);

export function registerSupplier(adapter: SupplierAdapter): void {
  adapters.set(adapter.id, adapter);
}

export function getSupplier(id: string | undefined | null): SupplierAdapter | undefined {
  return id ? adapters.get(id) : undefined;
}
