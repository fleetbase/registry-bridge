import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import compatibilityVerdict from '../../utils/compatibility';
import { verdictOf } from '../../utils/store-model';

const ICONS = { ready: 'check', needs_update: 'circle-exclamation', unavailable: 'circle-xmark' };

/**
 * "Can I install this?" in plain words. `inline` is the one-liner on tiles,
 * rows and the page header ("Works with your Fleetbase"); `card` is the
 * extension page's side card with the reason and, behind Show details, the
 * version ranges for admins.
 *
 * Renders nothing when the API sent no verdict, or for an installed extension
 * (inline), where it would only repeat what Open already says.
 *
 * @argument {Object} extension
 * @argument {String} [variant] inline (default) | short | card
 */
export default class RegistryVerdictComponent extends Component {
    @service intl;

    get verdict() {
        return verdictOf(this.args.extension);
    }

    get variant() {
        return ['inline', 'short', 'card'].includes(this.args.variant) ? this.args.variant : 'inline';
    }

    get show() {
        if (!this.verdict) {
            return false;
        }
        return this.variant === 'card' || this.args.extension?.is_installed !== true;
    }

    get icon() {
        return ICONS[this.verdict];
    }

    get text() {
        return this.intl.t(`registry-bridge.store.verdict.${this.variant === 'short' ? 'short' : 'inline'}.${this.verdict}`);
    }

    get detail() {
        const detail = compatibilityVerdict(this.intl, this.args.extension);
        // Installed and fine: say so rather than "Ready to install"
        if (detail && this.args.extension?.is_installed === true && this.verdict === 'ready') {
            return { ...detail, label: this.intl.t('registry-bridge.store.verdict.installed'), reason: this.intl.t('registry-bridge.store.verdict.installed-reason') };
        }
        return detail;
    }
}
