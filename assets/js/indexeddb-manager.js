/**
 * ALELTU POS — IndexedDB Manager & Local Transaction Engine
 * Version: 1.0.0
 * Enterprise Offline-First Business Data Storage
 */

const DB_NAME = 'AleltuDB';
const DB_VERSION = 2;

class IndexedDBManager {
    constructor() {
        this.db = null;
        this.initPromise = this.init();
    }

    async init() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                console.log('[IndexedDB] Upgrading schema to version:', DB_VERSION);

                // Products Store
                if (!db.objectStoreNames.contains('products')) {
                    const prodStore = db.createObjectStore('products', { keyPath: 'id' });
                    prodStore.createIndex('name', 'name', { unique: false });
                    prodStore.createIndex('branch_id', 'branch_id', { unique: false });
                }

                // Inventory Store
                if (!db.objectStoreNames.contains('seller_inventory')) {
                    const invStore = db.createObjectStore('seller_inventory', { keyPath: 'id' });
                    invStore.createIndex('item_name', 'item_name', { unique: false });
                    invStore.createIndex('branch_id', 'branch_id', { unique: false });
                }

                // Sales Store (keyed by sale_uuid)
                if (!db.objectStoreNames.contains('sales')) {
                    const salesStore = db.createObjectStore('sales', { keyPath: 'sale_uuid' });
                    salesStore.createIndex('status', 'status', { unique: false });
                    salesStore.createIndex('created_locally_at', 'created_locally_at', { unique: false });
                    salesStore.createIndex('branch_id', 'branch_id', { unique: false });
                }

                // Sale Items Store
                if (!db.objectStoreNames.contains('sale_items')) {
                    const itemsStore = db.createObjectStore('sale_items', { keyPath: 'id', autoIncrement: true });
                    itemsStore.createIndex('sale_uuid', 'sale_uuid', { unique: false });
                }

                // Outbox Store (keyed by event_uuid)
                if (!db.objectStoreNames.contains('outbox')) {
                    const outboxStore = db.createObjectStore('outbox', { keyPath: 'event_uuid' });
                    outboxStore.createIndex('status', 'status', { unique: false });
                    outboxStore.createIndex('priority', 'priority', { unique: false });
                    outboxStore.createIndex('created_at', 'created_at', { unique: false });
                }

                // Offline Rules Store
                if (!db.objectStoreNames.contains('offline_rules')) {
                    const rulesStore = db.createObjectStore('offline_rules', { keyPath: 'id' });
                    rulesStore.createIndex('priority', 'priority', { unique: false });
                    rulesStore.createIndex('rule_scope', 'rule_scope', { unique: false });
                }

                // Devices Store
                if (!db.objectStoreNames.contains('devices')) {
                    db.createObjectStore('devices', { keyPath: 'device_uuid' });
                }

                // Settings Store
                if (!db.objectStoreNames.contains('settings')) {
                    db.createObjectStore('settings', { keyPath: 'key' });
                }

                // Sync Conflicts Store
                if (!db.objectStoreNames.contains('sync_conflicts')) {
                    const conflictsStore = db.createObjectStore('sync_conflicts', { keyPath: 'conflict_uuid' });
                    conflictsStore.createIndex('status', 'status', { unique: false });
                }

                // === DB VERSION 2 NEW STORES ===

                // Categories
                if (!db.objectStoreNames.contains('categories')) {
                    const catStore = db.createObjectStore('categories', { keyPath: 'id' });
                    catStore.createIndex('branch_id', 'branch_id', { unique: false });
                }

                // Customers (for future CRM)
                if (!db.objectStoreNames.contains('customers')) {
                    const custStore = db.createObjectStore('customers', { keyPath: 'id' });
                    custStore.createIndex('phone', 'phone', { unique: false });
                    custStore.createIndex('branch_id', 'branch_id', { unique: false });
                }

                // Payments (split-payment support)
                if (!db.objectStoreNames.contains('payments')) {
                    const payStore = db.createObjectStore('payments', { keyPath: 'id', autoIncrement: true });
                    payStore.createIndex('sale_uuid', 'sale_uuid', { unique: false });
                    payStore.createIndex('method', 'method', { unique: false });
                }

                // Returns / Refunds
                if (!db.objectStoreNames.contains('returns')) {
                    const retStore = db.createObjectStore('returns', { keyPath: 'return_uuid' });
                    retStore.createIndex('sale_uuid', 'sale_uuid', { unique: false });
                    retStore.createIndex('status', 'status', { unique: false });
                    retStore.createIndex('created_at', 'created_at', { unique: false });
                }

                // Metadata (app version, last sync timestamps, etc.)
                if (!db.objectStoreNames.contains('metadata')) {
                    db.createObjectStore('metadata', { keyPath: 'key' });
                }

                // Sync Attempts Log (for diagnostics)
                if (!db.objectStoreNames.contains('sync_attempts')) {
                    const attStore = db.createObjectStore('sync_attempts', { keyPath: 'id', autoIncrement: true });
                    attStore.createIndex('event_uuid', 'event_uuid', { unique: false });
                    attStore.createIndex('attempted_at', 'attempted_at', { unique: false });
                    attStore.createIndex('result', 'result', { unique: false });
                }
            };

            request.onsuccess = (event) => {
                this.db = event.target.result;
                console.log('[IndexedDB] Database connected successfully');
                resolve(this.db);
            };

            request.onerror = (event) => {
                console.error('[IndexedDB] Error initializing database:', event.target.error);
                reject(event.target.error);
            };
        });
    }

    async ensureReady() {
        if (!this.db) {
            await this.initPromise;
        }
    }

    // Helper: UUID v4 Generator
    generateUUID() {
        if (typeof crypto !== 'undefined' && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    // Bulk Save Products Snapshot (incremental — uses put not clear)
    async syncProducts(productsList) {
        await this.ensureReady();
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(['products'], 'readwrite');
            const store = tx.objectStore('products');
            productsList.forEach(p => {
                store.put({
                    id: parseInt(p.id),
                    name: p.name || '',
                    code: p.code || '',
                    barcode: p.barcode || '',
                    category_id: parseInt(p.category_id || 0),
                    unit: p.unit || 'pcs',
                    unit_price: parseFloat(p.price || p.unit_price || 0),
                    tax_rate: parseFloat(p.tax_rate || 0),
                    branch_id: parseInt(p.branch_id || 0),
                    is_active: p.is_active !== undefined ? parseInt(p.is_active) : 1,
                    updated_at: p.updated_at || new Date().toISOString()
                });
            });
            tx.oncomplete = () => resolve(true);
            tx.onerror = (err) => reject(err);
        });
    }

    // Bulk Save Seller Inventory Snapshot (incremental — put not clear)
    async syncSellerInventory(inventoryList) {
        await this.ensureReady();
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(['seller_inventory'], 'readwrite');
            const store = tx.objectStore('seller_inventory');
            inventoryList.forEach(item => {
                store.put({
                    id: parseInt(item.id),
                    item_name: item.item_name || item.name || '',
                    product_id: parseInt(item.product_id || 0),
                    current_stock: parseFloat(item.quantity || item.current_stock || item.stock || 0),
                    quantity: parseFloat(item.quantity || item.current_stock || 0),
                    price: parseFloat(item.price || item.unit_price || 0),
                    unit: item.unit || 'pcs',
                    barcode: item.barcode || '',
                    tax_rate: parseFloat(item.tax_rate || 0),
                    allowed_offline_qty: parseFloat(item.allowed_offline_qty || 999999),
                    reserved_qty: parseFloat(item.reserved_qty || 0),
                    low_stock_alert: parseFloat(item.low_stock_alert || 5),
                    risk_level: item.risk_level || 'HIGH',
                    branch_id: parseInt(item.branch_id || 0),
                    seller_id: parseInt(item.seller_id || 0),
                    updated_at: item.updated_at || new Date().toISOString()
                });
            });
            tx.oncomplete = () => resolve(true);
            tx.onerror = (err) => reject(err);
        });
    }

    // Save Offline Rules
    async syncOfflineRules(rulesList) {
        await this.ensureReady();
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(['offline_rules'], 'readwrite');
            const store = tx.objectStore('offline_rules');
            store.clear();
            rulesList.forEach(r => store.put(r));
            tx.oncomplete = () => resolve(true);
            tx.onerror = (err) => reject(err);
        });
    }

    // Get all products locally
    async getProducts() {
        await this.ensureReady();
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(['products'], 'readonly');
            const req = tx.objectStore('products').getAll();
            req.onsuccess = () => resolve(req.result);
            req.onerror = (err) => reject(err);
        });
    }

    // Get seller inventory locally
    async getSellerInventory() {
        await this.ensureReady();
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(['seller_inventory'], 'readonly');
            const req = tx.objectStore('seller_inventory').getAll();
            req.onsuccess = () => resolve(req.result);
            req.onerror = (err) => reject(err);
        });
    }

    // An online sale is already authoritative on the server. Update only the
    // local inventory snapshot; it must never create an outbox event.
    async applyOnlineInventoryDelta(items) {
        await this.ensureReady();
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(['seller_inventory'], 'readwrite');
            const store = tx.objectStore('seller_inventory');
            const all = store.getAll();

            all.onsuccess = () => {
                const inventory = all.result;
                for (const cartItem of items) {
                    const match = inventory.find(item =>
                        item.id === cartItem.id ||
                        (item.item_name && cartItem.name &&
                         item.item_name.toLowerCase() === cartItem.name.toLowerCase())
                    );
                    if (match) {
                        match.current_stock = Math.max(0,
                            parseFloat(match.current_stock || 0) - parseFloat(cartItem.quantity || 0));
                        match.quantity = match.current_stock;
                        match.updated_at = new Date().toISOString();
                        store.put(match);
                    }
                }
            };
            all.onerror = () => reject(all.error);
            tx.oncomplete = () => resolve(true);
            tx.onerror = () => reject(tx.error);
        });
    }

    // Set & Get Settings
    async setSetting(key, value) {
        await this.ensureReady();
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(['settings'], 'readwrite');
            tx.objectStore('settings').put({ key, value });
            tx.oncomplete = () => resolve(true);
            tx.onerror = (err) => reject(err);
        });
    }

    async getSetting(key) {
        await this.ensureReady();
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(['settings'], 'readonly');
            const req = tx.objectStore('settings').get(key);
            req.onsuccess = () => resolve(req.result ? req.result.value : null);
            req.onerror = (err) => reject(err);
        });
    }

    /**
     * CORE REQUIREMENT #7: TRANSACTIONAL LOCAL SALE
     * Atomically creates sale, items, updates stock, creates outbox event.
     * Rolls back completely if any step fails.
     */
    async performLocalSale(saleData) {
        await this.ensureReady();
        return new Promise((resolve, reject) => {
            const saleUUID = saleData.sale_uuid || this.generateUUID();
            const eventUUID = this.generateUUID();
            const nowIso = new Date().toISOString();

            const tx = this.db.transaction(['sales', 'sale_items', 'seller_inventory', 'outbox'], 'readwrite');

            tx.onerror = (event) => {
                console.error('[IndexedDB] Local sale transaction failed and rolled back:', event.target.error);
                reject(event.target.error);
            };

            tx.oncomplete = () => {
                console.log('[IndexedDB] Local sale completed successfully. Sale UUID:', saleUUID);
                resolve({
                    success: true,
                    sale_uuid: saleUUID,
                    event_uuid: eventUUID,
                    created_locally_at: nowIso
                });
            };

            const salesStore = tx.objectStore('sales');
            const itemsStore = tx.objectStore('sale_items');
            const invStore = tx.objectStore('seller_inventory');
            const outboxStore = tx.objectStore('outbox');

            // ── PASS 1: READ all inventory, validate stock BEFORE touching anything ─
            // Mirrors the server-side FOR UPDATE + pre-validation in save_transaction.php
            // If ANY item has insufficient stock → reject entire sale → nothing is saved
            const invGetReq = invStore.getAll();
            invGetReq.onsuccess = () => {
                const inventory = invGetReq.result;

                // Build running stock map (same item may appear twice in cart)
                const stockMap = {};
                for (const invItem of inventory) {
                    const key = invItem.id;
                    stockMap[key] = {
                        record: invItem,
                        remaining: parseFloat(invItem.current_stock || 0)
                    };
                }

                // Pre-validate every cart item
                for (let cartItem of saleData.items) {
                    const match = inventory.find(i =>
                        i.id === cartItem.id ||
                        (i.item_name && i.item_name.toLowerCase() === cartItem.name.toLowerCase())
                    );

                    if (!match) {
                        // Item not in local inventory snapshot — warn but allow
                        // (server will do the final authoritative check on sync)
                        console.warn('[IndexedDB] Item not in local inventory, allowing with warning:', cartItem.name);
                        continue;
                    }

                    const mapKey = match.id;
                    if (!stockMap[mapKey]) {
                        stockMap[mapKey] = { record: match, remaining: parseFloat(match.current_stock || 0) };
                    }

                    stockMap[mapKey].remaining -= parseFloat(cartItem.quantity || 0);

                    if (stockMap[mapKey].remaining < 0) {
                        // ── REJECT: not enough stock ─────────────────────────────────
                        // This aborts the IndexedDB transaction automatically
                        const available = parseFloat(match.current_stock || 0).toFixed(2);
                        const requested = parseFloat(cartItem.quantity || 0).toFixed(2);
                        reject(new Error(
                            `የ "${cartItem.name}" ክምችት በቂ አይደለም።\n` +
                            `ያለው: ${available} | የተፈለገው: ${requested}\n` +
                            `(Not enough stock for "${cartItem.name}". Available: ${available}, Requested: ${requested})`
                        ));
                        return; // Stop — do not save anything
                    }
                }

                // ── PASS 2: All items validated — now deduct and save ────────────────
                for (let cartItem of saleData.items) {
                    const match = inventory.find(i =>
                        i.id === cartItem.id ||
                        (i.item_name && i.item_name.toLowerCase() === cartItem.name.toLowerCase())
                    );
                    if (match) {
                        match.current_stock = Math.max(0, parseFloat(match.current_stock || 0) - parseFloat(cartItem.quantity || 0));
                        invStore.put(match);
                    }
                }

                // 2. Insert Sale Record
                const saleRecord = {
                    sale_uuid: saleUUID,
                    seller_id: saleData.seller_id,
                    seller_name: saleData.seller_name,
                    branch_id: saleData.branch_id,
                    device_uuid: saleData.device_uuid || 'browser-pos',
                    total_amount: saleData.total_amount,
                    paid_amount: saleData.paid_amount,
                    change_amount: saleData.change_amount,
                    payment_method: saleData.payment_method || 'cash',
                    transaction_date: nowIso,
                    created_locally_at: nowIso,
                    synced_at: null,
                    status: 'PENDING'
                };
                salesStore.add(saleRecord);

                // 3. Insert Sale Items
                saleData.items.forEach(item => {
                    itemsStore.add({
                        sale_uuid: saleUUID,
                        product_id: item.id,
                        product_name: item.name,
                        quantity: item.quantity,
                        unit_price: item.price,
                        subtotal: item.subtotal || (item.quantity * item.price),
                        branch_id: saleData.branch_id
                    });
                });

                // 4. Create Outbox Queue Record (Idempotent event payload)
                const outboxRecord = {
                    event_uuid: eventUUID,
                    event_type: 'SALE',
                    entity_type: 'transaction',
                    entity_uuid: saleUUID,
                    sale_uuid: saleUUID,
                    payload: {
                        sale_uuid: saleUUID,
                        event_uuid: eventUUID,
                        seller_id: saleData.seller_id,
                        seller_name: saleData.seller_name,
                        branch_id: saleData.branch_id,
                        device_uuid: saleData.device_uuid || 'browser-pos',
                        total_amount: saleData.total_amount,
                        paid_amount: saleData.paid_amount,
                        change_amount: saleData.change_amount,
                        payment_method: saleData.payment_method,
                        created_locally_at: nowIso,
                        items: saleData.items
                    },
                    priority: 'CRITICAL',
                    status: 'PENDING',
                    attempt_count: 0,
                    created_at: nowIso,
                    last_attempt_at: null,
                    next_retry_at: nowIso,
                    error_code: null,
                    error_message: null
                };
                outboxStore.add(outboxRecord);
            };
        });
    }

    // Fetch Pending Outbox Events
    async getPendingOutbox() {
        await this.ensureReady();
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(['outbox'], 'readonly');
            const req = tx.objectStore('outbox').getAll();
            req.onsuccess = () => {
                const events = req.result.filter(e => e.status === 'PENDING' || e.status === 'FAILED');
                // Sort by priority (CRITICAL first) then created_at
                const priorityMap = { 'CRITICAL': 1, 'HIGH': 2, 'NORMAL': 3 };
                events.sort((a, b) => {
                    const pA = priorityMap[a.priority] || 9;
                    const pB = priorityMap[b.priority] || 9;
                    if (pA !== pB) return pA - pB;
                    return new Date(a.created_at) - new Date(b.created_at);
                });
                resolve(events);
            };
            req.onerror = (err) => reject(err);
        });
    }

    // Update Event Status in Outbox & Sales
    async markEventSynced(eventUUID, saleUUID) {
        await this.ensureReady();
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(['outbox', 'sales'], 'readwrite');
            const outboxStore = tx.objectStore('outbox');
            const salesStore = tx.objectStore('sales');

            const getEventReq = outboxStore.get(eventUUID);
            getEventReq.onsuccess = () => {
                if (getEventReq.result) {
                    const event = getEventReq.result;
                    event.status = 'SYNCED';
                    event.last_attempt_at = new Date().toISOString();
                    outboxStore.put(event);
                }
            };

            if (saleUUID) {
                const getSaleReq = salesStore.get(saleUUID);
                getSaleReq.onsuccess = () => {
                    if (getSaleReq.result) {
                        const sale = getSaleReq.result;
                        sale.status = 'SYNCED';
                        sale.synced_at = new Date().toISOString();
                        salesStore.put(sale);
                    }
                };
            }

            tx.oncomplete = () => resolve(true);
            tx.onerror = (err) => reject(err);
        });
    }

    async markEventFailed(eventUUID, errorCode, errorMessage) {
        await this.ensureReady();
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(['outbox', 'sales'], 'readwrite');
            const outboxStore = tx.objectStore('outbox');
            const salesStore = tx.objectStore('sales');
            const req = outboxStore.get(eventUUID);
            req.onsuccess = () => {
                if (req.result) {
                    const event = req.result;
                    event.attempt_count = (event.attempt_count || 0) + 1;
                    event.status = event.attempt_count >= 10 ? 'REQUIRES_REVIEW' : 'FAILED';
                    event.error_code = errorCode;
                    event.error_message = errorMessage;
                    event.last_attempt_at = new Date().toISOString();
                    outboxStore.put(event);
                    if (event.sale_uuid) {
                        const saleReq = salesStore.get(event.sale_uuid);
                        saleReq.onsuccess = () => {
                            if (saleReq.result) {
                                const sale = saleReq.result;
                                sale.status = errorCode === 'CONFLICT' ? 'CONFLICT' : 'FAILED';
                                sale.error_message = errorMessage;
                                salesStore.put(sale);
                            }
                        };
                    }
                }
            };
            tx.oncomplete = () => resolve(true);
            tx.onerror = (err) => reject(err);
        });
    }

    // Cancel a local-only sale. The reason is mandatory and a cancellation
    // report is queued so an administrator can review it when online again.
    async cancelPendingSale(saleUUID, reason) {
        await this.ensureReady();
        const cleanReason = String(reason || '').trim();
        if (!cleanReason) throw new Error('A cancellation reason is required.');
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(['sales', 'sale_items', 'seller_inventory', 'outbox'], 'readwrite');
            const sales = tx.objectStore('sales');
            const items = tx.objectStore('sale_items');
            const inventory = tx.objectStore('seller_inventory');
            const outbox = tx.objectStore('outbox');
            const saleReq = sales.get(saleUUID);
            saleReq.onsuccess = () => {
                const sale = saleReq.result;
                if (!sale || !['PENDING', 'FAILED', 'CONFLICT'].includes(sale.status)) {
                    reject(new Error('Only a waiting or conflicted offline sale can be cancelled.'));
                    return;
                }
                const itemReq = items.index('sale_uuid').getAll(saleUUID);
                itemReq.onsuccess = () => {
                    const cancelledAt = new Date().toISOString();
                    const invReq = inventory.getAll();
                    invReq.onsuccess = () => {
                        for (const line of itemReq.result || []) {
                            const match = invReq.result.find(row => row.id === line.product_id ||
                                (row.item_name && line.product_name && row.item_name.toLowerCase() === line.product_name.toLowerCase()));
                            if (match) {
                                match.current_stock = parseFloat(match.current_stock || 0) + parseFloat(line.quantity || 0);
                                match.quantity = match.current_stock;
                                match.updated_at = cancelledAt;
                                inventory.put(match);
                            }
                        }
                        sale.status = 'CANCELLED';
                        sale.cancel_reason = cleanReason;
                        sale.cancelled_locally_at = cancelledAt;
                        sales.put(sale);
                        const allEvents = outbox.getAll();
                        allEvents.onsuccess = () => {
                            for (const event of allEvents.result || []) {
                                if (event.sale_uuid === saleUUID && event.event_type === 'SALE') outbox.delete(event.event_uuid);
                            }
                            const eventUUID = this.generateUUID();
                            outbox.put({
                                event_uuid: eventUUID, event_type: 'SALE_CANCELLED', entity_type: 'transaction',
                                entity_uuid: saleUUID, sale_uuid: saleUUID, priority: 'HIGH', status: 'PENDING',
                                attempt_count: 0, created_at: cancelledAt, next_retry_at: cancelledAt,
                                payload: { event_uuid: eventUUID, event_type: 'SALE_CANCELLED', sale_uuid: saleUUID,
                                    seller_id: sale.seller_id, seller_name: sale.seller_name, branch_id: sale.branch_id,
                                    device_uuid: sale.device_uuid, total_amount: sale.total_amount,
                                    cancel_reason: cleanReason, cancelled_locally_at: cancelledAt,
                                    items: itemReq.result || [] }
                            });
                        };
                    };
                };
            };
            tx.oncomplete = () => resolve(true);
            tx.onerror = () => reject(tx.error);
        });
    }

    // Small, durable cancellation operation used by the POS queue. It changes
    // only the local queue state and creates the audit event in one transaction.
    // The next inventory snapshot restores the authoritative server quantity.
    async cancelQueuedSale(saleUUID, reason) {
        await this.ensureReady();
        const cleanReason = String(reason || '').trim();
        if (!cleanReason) throw new Error('A cancellation reason is required.');
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(['sales', 'outbox'], 'readwrite');
            const sales = tx.objectStore('sales');
            const outbox = tx.objectStore('outbox');
            const saleReq = sales.get(saleUUID);
            saleReq.onsuccess = () => {
                const sale = saleReq.result;
                if (!sale || !['PENDING', 'FAILED', 'CONFLICT'].includes(sale.status)) {
                    reject(new Error('This sale is no longer waiting to sync.'));
                    return;
                }
                const eventReq = outbox.getAll();
                eventReq.onsuccess = () => {
                    const now = new Date().toISOString();
                    for (const event of eventReq.result || []) {
                        if (event.sale_uuid === saleUUID && event.event_type === 'SALE') outbox.delete(event.event_uuid);
                    }
                    sale.status = 'CANCELLED';
                    sale.cancel_reason = cleanReason;
                    sale.cancelled_locally_at = now;
                    sales.put(sale);
                    const eventUUID = this.generateUUID();
                    outbox.put({
                        event_uuid: eventUUID, event_type: 'SALE_CANCELLED', entity_type: 'transaction',
                        entity_uuid: saleUUID, sale_uuid: saleUUID, priority: 'HIGH', status: 'PENDING',
                        attempt_count: 0, created_at: now, next_retry_at: now,
                        payload: { event_uuid: eventUUID, event_type: 'SALE_CANCELLED', sale_uuid: saleUUID,
                            seller_id: sale.seller_id, seller_name: sale.seller_name, branch_id: sale.branch_id,
                            device_uuid: sale.device_uuid, total_amount: sale.total_amount,
                            cancel_reason: cleanReason, cancelled_locally_at: now }
                    });
                };
            };
            tx.oncomplete = () => resolve(true);
            tx.onerror = () => reject(tx.error || new Error('Could not cancel the local sale.'));
        });
    }

    // v2 avoids keeping a write transaction open while reading multiple stores.
    // This is reliable across browsers and preserves the cancelled product lines
    // for the administrator's audit report.
    async cancelQueuedSaleSafely(saleUUID, reason) {
        await this.ensureReady();
        const cleanReason = String(reason || '').trim();
        if (!cleanReason) throw new Error('A cancellation reason is required.');

        const snapshot = await new Promise((resolve, reject) => {
            const tx = this.db.transaction(['sales', 'sale_items', 'outbox', 'seller_inventory'], 'readonly');
            let sale, lines, events, inventory;
            tx.objectStore('sales').get(saleUUID).onsuccess = e => { sale = e.target.result; };
            tx.objectStore('sale_items').index('sale_uuid').getAll(saleUUID).onsuccess = e => { lines = e.target.result || []; };
            tx.objectStore('outbox').getAll().onsuccess = e => { events = e.target.result || []; };
            tx.objectStore('seller_inventory').getAll().onsuccess = e => { inventory = e.target.result || []; };
            tx.oncomplete = () => resolve({ sale, lines: lines || [], events: events || [], inventory: inventory || [] });
            tx.onerror = () => reject(tx.error || new Error('Could not read the offline sale.'));
        });

        if (!snapshot.sale || !['PENDING', 'FAILED', 'CONFLICT'].includes(snapshot.sale.status)) {
            throw new Error('This sale is no longer waiting to sync.');
        }

        const now = new Date().toISOString();
        const eventUUID = this.generateUUID();
        await new Promise((resolve, reject) => {
            const tx = this.db.transaction(['sales', 'outbox', 'seller_inventory'], 'readwrite');
            const sales = tx.objectStore('sales');
            const outbox = tx.objectStore('outbox');
            const inventory = tx.objectStore('seller_inventory');

            const sale = { ...snapshot.sale, status: 'CANCELLED', cancel_reason: cleanReason, cancelled_locally_at: now };
            sales.put(sale);
            snapshot.events.forEach(event => {
                if (event.sale_uuid === saleUUID && event.event_type === 'SALE') outbox.delete(event.event_uuid);
            });
            snapshot.lines.forEach(line => {
                const match = snapshot.inventory.find(row => row.id === line.product_id ||
                    (row.item_name && line.product_name && row.item_name.toLowerCase() === line.product_name.toLowerCase()));
                if (match) {
                    const restored = { ...match };
                    restored.current_stock = parseFloat(restored.current_stock || 0) + parseFloat(line.quantity || 0);
                    restored.quantity = restored.current_stock;
                    restored.updated_at = now;
                    inventory.put(restored);
                }
            });
            outbox.put({
                event_uuid: eventUUID, event_type: 'SALE_CANCELLED', entity_type: 'transaction',
                entity_uuid: saleUUID, sale_uuid: saleUUID, priority: 'HIGH', status: 'PENDING',
                attempt_count: 0, created_at: now, next_retry_at: now,
                payload: { event_uuid: eventUUID, event_type: 'SALE_CANCELLED', sale_uuid: saleUUID,
                    seller_id: sale.seller_id, seller_name: sale.seller_name, branch_id: sale.branch_id,
                    device_uuid: sale.device_uuid, total_amount: sale.total_amount,
                    cancel_reason: cleanReason, cancelled_locally_at: now, items: snapshot.lines }
            });
            tx.oncomplete = () => resolve(true);
            tx.onerror = () => reject(tx.error || new Error('Could not save the cancellation.'));
        });
        return true;
    }

    // Get Summary Stats for Status Bar
    async getSyncStats() {
        await this.ensureReady();
        return new Promise((resolve, reject) => {
            const tx = this.db.transaction(['outbox'], 'readonly');
            const req = tx.objectStore('outbox').getAll();
            req.onsuccess = () => {
                const events = req.result;
                const waiting = events.filter(e => e.status === 'PENDING' || e.status === 'FAILED');
                // A cancellation report is audit-only; never display it as an
                // unsynced sale or count it as a product waiting to upload.
                const pending = waiting.filter(e => e.event_type === 'SALE').length;
                const pendingReports = waiting.filter(e => e.event_type === 'SALE_CANCELLED').length;
                const conflicts = events.filter(e => (e.status === 'CONFLICT' || e.status === 'REQUIRES_REVIEW') && e.event_type === 'SALE').length;
                const synced = events.filter(e => e.status === 'SYNCED').length;
                resolve({ pending, pendingReports, conflicts, synced, total: events.length });
            };
            req.onerror = (err) => reject(err);
        });
    }

    // Fetch all recorded offline sales with their line items
    async getAllSalesWithItems() {
        await this.ensureReady();
        return new Promise((resolve, reject) => {
            if (!this.db) {
                resolve([]);
                return;
            }
            const tx = this.db.transaction(['sales', 'sale_items'], 'readonly');
            const salesStore = tx.objectStore('sales');
            const itemsStore = tx.objectStore('sale_items');
            const allReq = salesStore.getAll();
            allReq.onsuccess = async () => {
                const sales = allReq.result || [];
                sales.sort((a, b) => new Date(b.created_locally_at) - new Date(a.created_locally_at));
                try {
                    const salesWithItems = await Promise.all(sales.map(sale => {
                        return new Promise((res2) => {
                            const idx = itemsStore.index('sale_uuid');
                            const r = idx.getAll(sale.sale_uuid);
                            r.onsuccess = () => res2({ ...sale, items: r.result || [] });
                            r.onerror = () => res2({ ...sale, items: [] });
                        });
                    }));
                    resolve(salesWithItems);
                } catch (e) {
                    resolve(sales);
                }
            };
            allReq.onerror = () => resolve([]);
        });
    }
}

// Global Singleton Instance
window.aleltuDB = new IndexedDBManager();
