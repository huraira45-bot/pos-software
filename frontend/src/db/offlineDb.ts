import { openDB, type DBSchema, type IDBPDatabase } from 'idb';
import type { PendingSale, Product } from '../types';

interface PosOfflineDb extends DBSchema {
  products: {
    key: number;
    value: Product;
    indexes: { 'by-barcode': string; 'by-item-code': string };
  };
  pendingSales: {
    key: string;
    value: PendingSale;
    indexes: { 'by-status': string };
  };
}

let dbPromise: Promise<IDBPDatabase<PosOfflineDb>> | null = null;

/**
 * One IndexedDB database backs the checkout screen's offline resilience:
 *  - `products`: a local mirror of the catalog, refreshed whenever online, so
 *    barcode scanning and search keep working through a connectivity drop.
 *  - `pendingSales`: finalized sales that couldn't reach the server yet. Each
 *    is a complete SaleRequest plus a locally-estimated total (for printing a
 *    receipt immediately) - the server is what actually allocates the USIN
 *    and fiscalizes, once `flushPendingSales` succeeds for that row.
 */
function getDb(): Promise<IDBPDatabase<PosOfflineDb>> {
  if (!dbPromise) {
    dbPromise = openDB<PosOfflineDb>('pos-offline', 1, {
      upgrade(db) {
        const products = db.createObjectStore('products', { keyPath: 'id' });
        products.createIndex('by-barcode', 'barcode');
        products.createIndex('by-item-code', 'item_code');

        const pendingSales = db.createObjectStore('pendingSales', { keyPath: 'localId' });
        pendingSales.createIndex('by-status', 'syncStatus');
      },
    });
  }
  return dbPromise;
}

export async function cacheProducts(products: Product[]): Promise<void> {
  const db = await getDb();
  const tx = db.transaction('products', 'readwrite');
  await Promise.all(products.map((p) => tx.store.put(p)));
  await tx.done;
}

export async function getCachedProducts(): Promise<Product[]> {
  const db = await getDb();
  return db.getAll('products');
}

export async function findCachedProductByBarcode(barcode: string): Promise<Product | undefined> {
  const db = await getDb();
  return db.getFromIndex('products', 'by-barcode', barcode);
}

export async function queuePendingSale(sale: PendingSale): Promise<void> {
  const db = await getDb();
  await db.put('pendingSales', sale);
}

export async function getPendingSales(): Promise<PendingSale[]> {
  const db = await getDb();
  return db.getAll('pendingSales');
}

export async function updatePendingSale(sale: PendingSale): Promise<void> {
  const db = await getDb();
  await db.put('pendingSales', sale);
}

export async function removePendingSale(localId: string): Promise<void> {
  const db = await getDb();
  await db.delete('pendingSales', localId);
}
