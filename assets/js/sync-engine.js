/**
 * ALELTU POS — Background Sync Engine v2.0
 * Incremental sync with version cursors, startup recovery, offline token auth.
 */

class SyncEngine {
    constructor() {
        this.isSyncing = false;
        this.syncInterval = null;
        this.listeners = [];
        this.BATCH_API     = 'api/sync/batch.php';
        this.SNAPSHOT_API  = 'api/sync/inventory-snapshot.php';
        this.TOKEN_API     = 'api/auth/issue-offline-token.php';
        this.VERSION_KEY   = 'aleltu_inventory_version';
        this.TOKEN_KEY     = 'aleltu_offline_token';
        this.init();
    }

    init() {
        window.addEventListener('online',  () => { console.log('[Sync] Online'); this.triggerSync(); this.refreshInventorySnapshot(); });
        window.addEventListener('offline', () => { console.log('[Sync] Offline'); this.notifyStatusChange(); });
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') this.triggerSync(); });

        // Startup recovery: process any pending outbox from before power/browser failure
        this._startupRecovery();

        // Periodic sync every 30 s
        this.syncInterval = setInterval(() => this.triggerSync(), 30000);

        // Refresh inventory snapshot every 5 min
        setInterval(() => this.refreshInventorySnapshot(), 300000);
    }

    /** On page load, check for unsent sales and re-queue them. */
    async _startupRecovery() {
        try {
            if (!window.aleltuDB) return;
            const stats = await window.aleltuDB.getSyncStats();
            if (stats.pending > 0) {
                console.log(`[Sync] Startup recovery: ${stats.pending} unsent events found. Queuing sync…`);
                setTimeout(() => this.triggerSync(), 2000);
            }
        } catch (e) { /* non-fatal */ }
    }

    onStatusChange(cb) { this.listeners.push(cb); }

    async notifyStatusChange() {
        if (!window.aleltuDB) return;
        const stats = await window.aleltuDB.getSyncStats();
        this.listeners.forEach(cb => cb({
            isOnline: navigator.onLine,
            isSyncing: this.isSyncing,
            pending: stats.pending,
            conflicts: stats.conflicts,
            synced: stats.synced,
            total: stats.total
        }));
    }

    _getAuthHeaders() {
        const token = this._getOfflineToken();
        const deviceUUID = window.deviceManager ? window.deviceManager.getDeviceUUID() : 'browser-pos';
        const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
        if (token) {
            headers['X-Offline-Token'] = token.token;
            headers['X-Device-UUID']   = deviceUUID;
        }
        return headers;
    }

    _getOfflineToken() {
        try { return JSON.parse(localStorage.getItem(this.TOKEN_KEY)); } catch { return null; }
    }

    async issueOfflineToken(deviceUUID) {
        if (!navigator.onLine) return;
        try {
            const res = await fetch(this.TOKEN_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ device_uuid: deviceUUID })
            });
            if (res.ok) {
                const data = await res.json();
                if (data.success) {
                    localStorage.setItem(this.TOKEN_KEY, JSON.stringify({ token: data.token, expires_at: data.expires_at }));
                    console.log('[Sync] Offline token issued, expires:', data.expires_at);
                }
            }
        } catch (e) { console.warn('[Sync] Could not issue offline token:', e.message); }
    }

    async triggerSync() {
        if (this.isSyncing || !navigator.onLine) { this.notifyStatusChange(); return; }
        try {
            this.isSyncing = true;
            this.notifyStatusChange();

            const pendingEvents = await window.outboxManager.getNextEventsToSync();
            if (pendingEvents.length === 0) { this.isSyncing = false; this.notifyStatusChange(); return; }

            console.log(`[Sync] Batch sync: ${pendingEvents.length} events`);

            const deviceUUID = window.deviceManager ? window.deviceManager.getDeviceUUID() : 'browser-pos';
            const batchPayload = { device_id: deviceUUID, events: pendingEvents.map(e => e.payload) };

            const response = await fetch(this.BATCH_API, {
                method: 'POST',
                headers: this._getAuthHeaders(),
                body: JSON.stringify(batchPayload)
            });

            if (response.status === 401) { console.warn('[Sync] Auth expired. Will retry after token refresh.'); return; }
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();
            if (data && data.results && Array.isArray(data.results)) {
                for (const res of data.results) {
                    if (res.status === 'SYNCED' || (res.code === 'ALREADY_PROCESSED')) {
                        await window.outboxManager.recordSyncSuccess(res.event_uuid, res.sale_uuid);
                    } else if (res.status === 'CONFLICT') {
                        await window.aleltuDB.markEventFailed(res.event_uuid, 'CONFLICT', res.message || 'Conflict');
                    } else {
                        await window.outboxManager.recordSyncFailure(res.event_uuid, res.code || 'SYNC_ERROR', res.message || 'Unknown error');
                    }
                }
                if (data.summary) console.log('[Sync] Summary:', data.summary);
            }
        } catch (err) {
            console.warn('[Sync] Deferred:', err.message);
        } finally {
            this.isSyncing = false;
            this.notifyStatusChange();
        }
    }

    /** Incremental inventory refresh using version cursors */
    async refreshInventorySnapshot() {
        if (!navigator.onLine || !window.aleltuDB) return;
        try {
            const lastVersion = parseInt(localStorage.getItem(this.VERSION_KEY) || '0', 10);
            const res = await fetch(`${this.SNAPSHOT_API}?since_version=${lastVersion}`, {
                headers: this._getAuthHeaders()
            });
            if (!res.ok) return;
            const data = await res.json();
            if (!data.success) return;

            if (!data.has_changes) { console.log('[Sync] Inventory up to date at v' + data.server_version); return; }

            if (data.products && data.products.length > 0) await window.aleltuDB.syncProducts(data.products);
            if (data.inventory && data.inventory.length > 0) await window.aleltuDB.syncSellerInventory(data.inventory);
            if (data.offline_rules && data.offline_rules.length > 0) await window.aleltuDB.syncOfflineRules(data.offline_rules);

            localStorage.setItem(this.VERSION_KEY, String(data.server_version));
            console.log(`[Sync] Inventory updated to v${data.server_version}. Products: ${data.products.length}, Inventory: ${data.inventory.length}`);
        } catch (e) { console.warn('[Sync] Inventory snapshot deferred:', e.message); }
    }

    /** Check storage quota and warn if > 80% full */
    async checkStorageQuota() {
        if (!navigator.storage || !navigator.storage.estimate) return null;
        const est = await navigator.storage.estimate();
        const usedPct = est.usage / est.quota * 100;
        if (usedPct > 80) {
            console.warn(`[Sync] Storage ${usedPct.toFixed(1)}% full!`);
            this.listeners.forEach(cb => cb({ storageWarning: true, storageUsedPct: usedPct }));
        }
        return { usage: est.usage, quota: est.quota, usedPct };
    }

    forceSyncNow() { this.triggerSync(); }
}

window.syncEngine = new SyncEngine();
