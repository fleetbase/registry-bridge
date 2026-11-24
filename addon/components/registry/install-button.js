import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { htmlSafe } from '@ember/template';
import { guidFor } from '@ember/object/internals';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';
import { PERMISSIONS } from '../../utils/extension-state';
import { buttonState, openExtensionPage } from '../../utils/store-model';
import locateExtension from '../../utils/extension-location';

/**
 * The one install button (design: "the button is the progress bar"). It
 * reads the extension's state and becomes Get, Buy, Update, Open, a disabled
 * "Can't install yet" / "Not available", or, while an install runs, a
 * progress bar labelled Downloading, Installing or Finishing up.
 *
 * @argument {Object} extension a catalog record
 * @argument {Object} [progress] the running job ({ stage, percent } or a job record); defaults to the
 *   extension's job in the install-jobs service, so every button for it shows the same progress
 * @argument {String} [size] sm (tiles, rows) | md (default) | lg (extension page)
 * @argument {Function} [onAction] called with `(kind, extension)` instead of the default action
 * @argument {Function} [onOpen] what Open does (defaults to the extension's page)
 */
export default class RegistryInstallButtonComponent extends Component {
    @service abilities;
    @service intl;
    @service installJobs;
    @service extensionPurchase;
    @service hostRouter;
    @service universe;

    hintId = `${guidFor(this)}-hint`;

    get state() {
        return buttonState(this.args.extension ?? {}, {
            progress: this.args.progress ?? this.installJobs.progressFor(this.args.extension),
            canInstall: this.abilities.can(PERMISSIONS.install),
            canPurchase: this.abilities.can(PERMISSIONS.purchase),
        });
    }

    get size() {
        return ['sm', 'md', 'lg'].includes(this.args.size) ? this.args.size : 'md';
    }

    get isProgress() {
        return this.state.kind === 'progress';
    }

    get fillStyle() {
        return htmlSafe(`width:${this.state.percent ?? 0}%`);
    }

    get label() {
        const state = this.state;
        const t = (key, options) => this.intl.t(`registry-bridge.store.button.${key}`, options);
        switch (state.kind) {
            case 'progress':
                return state.stage === 'finishing' ? t('finishing') : t(state.stage, { percent: state.percent });
            case 'buy':
                return this.size === 'sm' ? formatCurrency(state.price.amount, state.price.currency) : t('buy', { price: formatCurrency(state.price.amount, state.price.currency) });
            case 'get':
                return this.size === 'sm' || !state.price?.free ? t('get') : t('get-free');
            default:
                return t(state.kind);
        }
    }

    /** Why the button is disabled, for its tooltip and screen readers. */
    get hint() {
        const kind = this.state.kind;
        return ['blocked', 'unavailable', 'registration', 'unauthorized'].includes(kind) ? this.intl.t(`registry-bridge.store.button.hint.${kind}`) : null;
    }

    @action run(event) {
        event?.stopPropagation?.();
        const { kind } = this.state;
        const extension = this.args.extension;
        if (this.state.disabled || kind === 'progress') {
            return;
        }
        if (typeof this.args.onAction === 'function') {
            return this.args.onAction(kind, extension);
        }

        switch (kind) {
            case 'get':
                return this.installJobs.start('install', extension);
            case 'update':
                return this.installJobs.start('update', extension);
            case 'buy':
                return this.extensionPurchase.purchase(extension);
            case 'open': {
                if (typeof this.args.onOpen === 'function') {
                    return this.args.onOpen(extension);
                }
                // Open goes where the extension lives (e.g. Fleet-Ops → Settings → LocoNav), else its page
                const location = locateExtension(this.universe, extension);
                return location ? this.hostRouter.transitionTo(location.route, ...location.models, { queryParams: location.queryParams }) : openExtensionPage(this.hostRouter, extension);
            }
        }
    }
}
