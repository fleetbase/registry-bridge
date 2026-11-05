import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

/** `revoked_reason` values the Registry Service sets; anything else reads as a plain revoke. */
const REASONS = ['refunded', 'disputed', 'staff'];

/**
 * Licence column on Purchased: Active, Refunded or Revoked (`meta.licence_state`), with the service's
 * `revoked_reason` as help text. A refunded or revoked licence stays listed for its receipt but no longer
 * entitles installs or updates.
 */
export default class CellLicenceStateComponent extends Component {
    @service intl;

    get state() {
        return this.args.row?.meta?.licence_state ?? null;
    }

    /** The service's `revoked_reason` when this bridge knows it, else `other`. */
    get reason() {
        const reason = this.args.row?.meta?.licence?.revoked_reason;
        return REASONS.includes(reason) ? reason : 'other';
    }

    get badge() {
        const t = (key) => this.intl.t(`registry-bridge.purchased.licence.${key}`);
        const help = t(`reason.${this.reason}`);

        switch (this.state) {
            case 'active':
                return { text: t('active'), status: 'success', help: null };
            case 'refunded':
                return { text: t('refunded'), status: 'warning', help };
            case 'revoked':
                return { text: t('revoked'), status: 'error', help };
            case null:
            case undefined:
                return null;
            default:
                return { text: t('inactive'), status: 'default', help: null };
        }
    }
}
