/**
 * ALELTU POS — Device Identity Manager
 * Ensures permanent device identity and authorization checks.
 */

class DeviceManager {
    constructor() {
        this.deviceUUIDKey = 'aleltu_device_uuid';
        this.deviceNameKey = 'aleltu_device_name';
        this.deviceUUID = this.initDeviceUUID();
    }

    initDeviceUUID() {
        let uuid = localStorage.getItem(this.deviceUUIDKey);
        if (!uuid) {
            if (typeof crypto !== 'undefined' && crypto.randomUUID) {
                uuid = crypto.randomUUID();
            } else {
                uuid = 'POS-DEV-' + Math.random().toString(36).substr(2, 9).toUpperCase();
            }
            localStorage.setItem(this.deviceUUIDKey, uuid);
        }
        return uuid;
    }

    getDeviceUUID() {
        return this.deviceUUID;
    }

    getDeviceName() {
        let name = localStorage.getItem(this.deviceNameKey);
        if (!name) {
            name = 'POS-TERMINAL-' + this.deviceUUID.substr(0, 6).toUpperCase();
            localStorage.setItem(this.deviceNameKey, name);
        }
        return name;
    }

    // ── Show a styled banner instead of a jarring alert() ─────────────────
    // The page stays fully usable for ONLINE sales. Only offline is disabled.
    _showRevokedBanner(deviceUUID) {
        const existing = document.getElementById('__deviceRevokedBanner');
        if (existing) return; // already shown

        const banner = document.createElement('div');
        banner.id = '__deviceRevokedBanner';
        banner.style.cssText = [
            'position:fixed', 'top:0', 'left:0', 'right:0', 'z-index:999999',
            'background:linear-gradient(135deg,#7f1d1d,#991b1b)',
            'color:#fff', 'padding:14px 20px',
            'display:flex', 'align-items:center', 'justify-content:space-between',
            'font-family:system-ui,sans-serif', 'font-size:14px',
            'box-shadow:0 4px 20px rgba(0,0,0,.4)',
            'border-bottom:2px solid #ef4444'
        ].join(';');

        const shortUUID = (deviceUUID || '').substring(0, 12).toUpperCase();
        banner.innerHTML =
            '<div style="display:flex;align-items:center;gap:12px">' +
                '<span style="font-size:22px">&#x1F6AB;</span>' +
                '<div>' +
                    '<div style="font-weight:700;font-size:15px">' +
                        'ይህ ተርሚናል ታግዷል &mdash; Device Revoked (' + shortUUID + ')' +
                    '</div>' +
                    '<div style="opacity:.85;font-size:12px;margin-top:2px">' +
                        'ኦፍላይን ሽያጭ ታግዷል። እባክዎ አስተዳዳሪውን ያነጋግሩ። &nbsp;|&nbsp; ' +
                        'Offline selling disabled. Contact your system administrator.' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<button onclick="document.getElementById(\'__deviceRevokedBanner\').remove()" ' +
                'style="background:rgba(255,255,255,.15);border:none;color:#fff;' +
                'padding:6px 14px;border-radius:6px;cursor:pointer;font-size:13px;flex-shrink:0">' +
                'ዝጋ &#x2715;' +
            '</button>';

        // Insert at the very top of body, above everything
        if (document.body) {
            document.body.insertBefore(banner, document.body.firstChild);
        } else {
            document.addEventListener('DOMContentLoaded', function() {
                document.body.insertBefore(banner, document.body.firstChild);
            });
        }
        console.error('[DeviceManager] Device REVOKED by Admin. UUID:', deviceUUID);
    }

    async registerDevice(branchId) {
        if (!navigator.onLine) return;
        try {
            const payload = {
                device_uuid: this.getDeviceUUID(),
                device_name: this.getDeviceName(),
                branch_id: branchId,
                app_version: '1.0.0'
            };
            const response = await fetch('api/devices/register.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await response.json();
            if (data && data.status === 'REVOKED') {
                this._showRevokedBanner(this.getDeviceUUID());
            }
        } catch (e) {
            // Device registration is non-critical — app still works online
            console.warn('[DeviceManager] Device registration deferred:', e.message);
        }
    }
}

window.deviceManager = new DeviceManager();
