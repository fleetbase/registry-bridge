import Component from '@glimmer/component';
import { priceOf } from '../../utils/extension-state';

/**
 * Price column on Purchased: what the organization paid (the receipt's total, tax included) when the
 * service or this instance recorded it, else the listing price. Struck through once the licence is
 * refunded or revoked.
 */
export default class CellPurchasePriceComponent extends Component {
    get extension() {
        return this.args.row ?? {};
    }

    get purchase() {
        const purchase = this.extension.meta?.licence?.purchase;
        return purchase && typeof purchase === 'object' ? purchase : {};
    }

    get amount() {
        const paid = Number(this.purchase.amount_total);
        if (Number.isFinite(paid) && paid > 0) {
            return paid;
        }
        const listed = priceOf(this.extension);
        return listed.free || !(listed.amount > 0) ? null : listed.amount;
    }

    get currency() {
        return (Number(this.purchase.amount_total) > 0 && this.purchase.currency) || this.extension.currency || 'USD';
    }

    get revoked() {
        const state = this.extension.meta?.licence_state;
        return state === 'refunded' || state === 'revoked';
    }
}
