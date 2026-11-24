import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { guidFor } from '@ember/object/internals';
import { htmlSafe } from '@ember/template';
import formatDate from '@fleetbase/ember-ui/utils/format-date';
import { PERMISSIONS } from '../../utils/extension-state';
import { isVerified, openExtensionPage } from '../../utils/store-model';
import locateExtension from '../../utils/extension-location';

/**
 * One row of Your extensions (design D): icon, name and where it lives, the
 * plan, the status, the action (Open, Update, Install) and Remove. Remove
 * opens a small popover on the row that says what stops and what stays;
 * while the uninstall runs the row dims with a thin bar, and a failure stays
 * on the row with Try again.
 *
 * @argument {Object} extension
 * @argument {String} [variant] installed (default) | purchased
 */
export default class RegistryLibraryRowComponent extends Component {
    @service installJobs;
    @service abilities;
    @service hostRouter;
    @service universe;
    @service intl;
    @tracked confirming = false;

    popoverId = `${guidFor(this)}-remove`;

    get extension() {
        return this.args.extension ?? {};
    }

    get isPurchasedView() {
        return this.args.variant === 'purchased';
    }

    get verified() {
        return isVerified(this.extension);
    }

    /** "Fleet-Ops → Settings → LocoNav" for an installed extension, else its one-line description. */
    get note() {
        if (this.extension.is_installed === true) {
            const location = locateExtension(this.universe, this.extension);
            if (location) {
                return location.path.join(' → ');
            }
        }
        return this.extension.subtitle || this.extension.description || null;
    }

    get licence() {
        return this.extension.meta?.licence ?? null;
    }

    /** Free, Bought, Renews <date>, Refunded or Revoked: what the organization has, in plain words. */
    get plan() {
        const t = (key, options) => this.intl.t(`registry-bridge.store.library.plan.${key}`, options);
        if (this.extension.payment_required !== true) {
            return t('free');
        }
        const state = this.extension.meta?.licence_state;
        if (state === 'refunded' || state === 'revoked') {
            return t(state);
        }
        const expires = this.licence?.expires_at;
        const date = expires ? new Date(expires) : null;
        return date && !isNaN(date) ? t('renews', { date: formatDate(date, 'dd MMM yyyy') }) : t('bought');
    }

    get status() {
        const t = (key) => this.intl.t(`registry-bridge.store.library.status.${key}`);
        if (this.isPurchasedView) {
            return this.extension.is_installed === true ? { text: t('installed'), tone: 'ok' } : { text: t('not-installed'), tone: 'muted' };
        }
        return this.extension.update_available === true ? { text: t('update'), tone: 'info' } : { text: t('current'), tone: 'ok' };
    }

    get receiptUrl() {
        const url = this.licence?.receipt_url ?? this.extension.meta?.receipt_url;
        return typeof url === 'string' && /^https?:\/\//i.test(url) ? url : null;
    }

    get canRemove() {
        return this.extension.is_installed === true && this.extension.core_extension !== true && this.abilities.can(PERMISSIONS.uninstall);
    }

    get removal() {
        return this.installJobs.removalFor(this.extension);
    }

    get barStyle() {
        return htmlSafe(`width:${Math.max(4, this.removal?.percent ?? 0)}%`);
    }

    get failure() {
        const failure = this.installJobs.failureFor(this.extension);
        return failure?.action === 'uninstall' ? failure : null;
    }

    get canSeeDetails() {
        return this.installJobs.canSeeDetails('uninstall');
    }

    /** What stops and what stays, for the popover. */
    get effect() {
        const name = this.extension.name;
        const where = this.extension.is_installed === true ? locateExtension(this.universe, this.extension)?.path?.[0] : null;
        const key = this.extension.payment_required === true ? 'paid' : where ? 'in-app' : 'default';
        return this.intl.t(`registry-bridge.store.library.remove.effect.${key}`, { name, app: where ?? '' });
    }

    @action open() {
        return openExtensionPage(this.hostRouter, this.extension);
    }

    @action toggleConfirm() {
        this.confirming = !this.confirming;
    }

    @action closeConfirm() {
        this.confirming = false;
    }

    @action onKeydown(event) {
        if (event.key === 'Escape') {
            this.confirming = false;
        }
    }

    @action focusFirst(element) {
        element.querySelector('button')?.focus();
    }

    @action remove() {
        this.confirming = false;
        return this.installJobs.start('uninstall', this.extension);
    }

    @action retry() {
        this.installJobs.dismiss(this.extension);
        return this.installJobs.start('uninstall', this.extension);
    }

    @action dismiss() {
        this.installJobs.dismiss(this.extension);
    }
}
