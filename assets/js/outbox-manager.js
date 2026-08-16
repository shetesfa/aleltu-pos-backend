/**
 * ALELTU POS — Outbox & Queue Manager
 * Maintains local outbox durability and handles status progression.
 */

class OutboxManager {
    constructor() {
        this.isProcessing = false;
    }

    // Exponential Backoff Delay Calculation (in seconds)
    calculateNextRetryDelay(attemptCount) {
        const delays = [0, 5, 15, 45, 120, 300]; // 0s, 5s, 15s, 45s, 2m, 5m
        if (attemptCount < delays.length) {
            return delays[attemptCount];
        }
        return 300; // max 5 minutes
    }

    async enqueueSaleEvent(saleData) {
        if (!window.aleltuDB) return null;
        return await window.aleltuDB.performLocalSale(saleData);
    }

    async getNextEventsToSync() {
        if (!window.aleltuDB) return [];
        const pendingEvents = await window.aleltuDB.getPendingOutbox();
        const now = new Date();

        return pendingEvents.filter(event => {
            if (!event.next_retry_at) return true;
            return new Date(event.next_retry_at) <= now;
        });
    }

    async recordSyncSuccess(eventUUID, saleUUID) {
        if (!window.aleltuDB) return;
        await window.aleltuDB.markEventSynced(eventUUID, saleUUID);
    }

    async recordSyncFailure(eventUUID, errorCode, errorMessage) {
        if (!window.aleltuDB) return;
        await window.aleltuDB.markEventFailed(eventUUID, errorCode, errorMessage);
    }
}

window.outboxManager = new OutboxManager();
