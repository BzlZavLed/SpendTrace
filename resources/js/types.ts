export interface Identifier { kind: string; last_four: string; device_label: string | null }
export interface Account { id: number; institution: string; nickname: string; card_network: string | null; identifiers: Identifier[] }
export interface Item { id?: number; amount_minor?: number | null; description: string | null; quantity: string | null; amount: string | null; category: string | null }
export interface Receipt { id: number; status: string; provider: string; error: string | null; created_at: string; images?: {id: number}[]; runs?: {id: number; generation: number; provider: string; version: string; output: unknown; error: string | null}[] }
export interface Transaction { id: number; total_minor: number | null; merchant: string | null; purchase_date: string | null; purchase_time: string | null; amount: string | null; currency: string | null; category: string | null; account_id: number | null; account?: Account | null; receipt: Receipt | null; observations?: {source_type:string;source_id:string;payload:unknown;observed_at:string|null}[]; items: Item[]; revision: number; review_status: string; reconciliation_status: string; review_reasons: string[] | null; corrected_fields: string[] | null; excluded: boolean }
export function money(t: {amount: string | null; currency: string | null; total_minor?: number | null}): string { return t.amount === null ? (t.total_minor != null ? 'Select currency to show amount' : 'Amount unknown') : t.currency ? `${t.currency} ${t.amount}` : `${t.amount} · Currency unconfirmed`; }
export function dateLabel(value: string | null): string { if (!value) return 'Purchase date unknown'; const [y,m,d] = value.split('-').map(Number); return new Intl.DateTimeFormat('en-US', {month:'short', day:'numeric', year:'numeric', timeZone:'UTC'}).format(new Date(Date.UTC(y,m-1,d))); }

/** Format exact minor units only after the user identifies the currency. */
export function formatMinorUnits(minor: number | null | undefined, currency: string | null, currencies: Record<string, number>): string | null {
    if (minor == null || !Number.isSafeInteger(minor) || !currency || !Object.hasOwn(currencies, currency)) return null;
    const digits = currencies[currency];
    if (!Number.isInteger(digits) || digits < 0 || digits > 3) return null;
    const value = BigInt(minor);
    const absolute = value < 0n ? -value : value;
    const factor = 10n ** BigInt(digits);
    return `${value < 0n ? '-' : ''}${absolute / factor}${digits ? '.' + String(absolute % factor).padStart(digits, '0') : ''}`;
}
