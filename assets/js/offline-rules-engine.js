/**
 * ALELTU POS — Deterministic Offline Rules Engine
 * Enforces Offline Quantity Limits & Precedence Rules
 * Precedence Order:
 * 1. Holiday / Date / Time Rules
 * 2. Device-Specific Rule
 * 3. Seller-Specific Rule
 * 4. Product-Specific Rule
 * 5. Category-Specific Rule
 * 6. Branch-Specific Rule
 * 7. Global Rule
 */

class OfflineRulesEngine {
    constructor() {
        this.rules = [];
    }

    setRules(rulesList) {
        this.rules = rulesList || [];
    }

    /**
     * Evaluate effective rule for a given context and cart item
     * @param {Object} context - { branch_id, seller_id, device_uuid, is_holiday, current_date }
     * @param {Object} item - { product_id, category_id, requested_qty }
     * @returns {Object} { allowed: boolean, max_qty: number, reason: string, effective_rule: Object }
     */
    evaluateRule(context, item) {
        if (!this.rules || this.rules.length === 0) {
            return {
                allowed: true,
                max_qty: 999999,
                reason: 'Default global policy (no active restrictions)',
                effective_rule: null
            };
        }

        const currentDate = context.current_date ? new Date(context.current_date) : new Date();
        const currentDayStr = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][currentDate.getDay()];

        // Filter valid active rules
        const activeRules = this.rules.filter(rule => {
            // Check date bounds
            if (rule.start_date && new Date(rule.start_date) > currentDate) return false;
            if (rule.end_date && new Date(rule.end_date) < currentDate) return false;
            
            // Check day of week
            if (rule.day_of_week && !rule.day_of_week.includes(currentDayStr)) return false;

            return true;
        });

        // Sort rules by explicit priority (lower priority number = higher precedence)
        activeRules.sort((a, b) => parseInt(a.priority || 100) - parseInt(b.priority || 100));

        // Evaluate according to Precedence Cascade
        
        // 1. Holiday / Special Date Rule
        if (context.is_holiday) {
            const holidayRule = activeRules.find(r => r.is_holiday === 1 || r.rule_scope === 'HOLIDAY');
            if (holidayRule) {
                return this.buildResult(holidayRule, item, 'Holiday override rule');
            }
        }

        // 2. Device Rule
        if (context.device_uuid) {
            const devRule = activeRules.find(r => r.rule_scope === 'DEVICE' && String(r.target_id) === String(context.device_uuid));
            if (devRule) return this.buildResult(devRule, item, 'Device-specific rule');
        }

        // 3. Seller Rule
        if (context.seller_id) {
            const sellerRule = activeRules.find(r => r.rule_scope === 'SELLER' && parseInt(r.target_id) === parseInt(context.seller_id));
            if (sellerRule) return this.buildResult(sellerRule, item, 'Seller-specific rule');
        }

        // 4. Product Rule
        if (item.product_id) {
            const prodRule = activeRules.find(r => r.rule_scope === 'PRODUCT' && parseInt(r.target_id) === parseInt(item.product_id));
            if (prodRule) return this.buildResult(prodRule, item, 'Product-specific rule');
        }

        // 5. Category Rule
        if (item.category_id) {
            const catRule = activeRules.find(r => r.rule_scope === 'CATEGORY' && parseInt(r.target_id) === parseInt(item.category_id));
            if (catRule) return this.buildResult(catRule, item, 'Category-specific rule');
        }

        // 6. Branch Rule
        if (context.branch_id) {
            const branchRule = activeRules.find(r => r.rule_scope === 'BRANCH' && parseInt(r.target_id) === parseInt(context.branch_id));
            if (branchRule) return this.buildResult(branchRule, item, 'Branch-specific rule');
        }

        // 7. Global Rule
        const globalRule = activeRules.find(r => r.rule_scope === 'GLOBAL');
        if (globalRule) return this.buildResult(globalRule, item, 'Global system rule');

        // Fallback default
        return {
            allowed: true,
            max_qty: 999999,
            reason: 'Standard offline allowance',
            effective_rule: null
        };
    }

    buildResult(rule, item, scopeDescription) {
        const allowed = parseInt(rule.allow_offline) === 1;
        const maxQty = parseFloat(rule.max_offline_qty || 0);
        const reqQty = parseFloat(item.requested_qty || 0);
        const pName = item.name || item.product_name || rule.rule_name || 'ምርት (Product)';

        let finalAllowed = allowed;
        let reason = '';

        if (!allowed) {
            finalAllowed = false;
            reason = `ይህ ምርት (${pName}) ኦፍላይን ለመሸጥ ተከልክሏል። (This product is not allowed to be sold offline. Please connect to internet.)`;
        } else if (reqQty > maxQty && maxQty > 0) {
            finalAllowed = false;
            reason = `የተጠየቀው መጠን (${reqQty}) ከኦፍላይን ገደብ (${maxQty}) በላይ ነው። (Requested quantity ${reqQty} exceeds max offline limit of ${maxQty} for ${pName})`;
        } else {
            reason = 'ፈቃድ ተሰጥቷል (Allowed)';
        }

        return {
            allowed: finalAllowed,
            max_qty: maxQty,
            reason: reason,
            effective_rule: rule
        };
    }
}

window.offlineRulesEngine = new OfflineRulesEngine();
