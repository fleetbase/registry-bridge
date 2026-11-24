import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { htmlSafe } from '@ember/template';
import { guidFor } from '@ember/object/internals';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';
import { PERMISSIONS } from '../../utils/extension-state';
import { buttonState } from '../../utils/store-model';

/**
 * The one install button (design: "the button is the progress bar"). It
 * reads the extension's state and becomes Get, Buy, Update, Open, a disabled
 * "Can't install yet" / "Not available", or, while an install runs, a
 * progress bar labelled Downloading, Installing or Finishing up.
 *
 * @argument {Object} extension a catalog record
 * @argument {Object} [progress] the running job ({ stage, percent } or a job record)
 * @argument {String} [size] sm (tiles, rows) | md (default) | lg (extension page)
 * @argument {Function} [onAction] called with `(kind, extension)` instead of the default action
 * @argument {Function} [onOpen] what Open does (defaults to the extension's page)
 */
export default class RegistryInstallButtonComponent extends Component {
    @service abilities;
    @service intl;
    @service extensionInstaller;
    @service extensionPurchase;
    @service extensionPanel;

    hintId = `${guidFor(this)}-hint`;

    get state() {
        return buttonState(this.args.extension ?? {}, {
            progress: this.args.progress,
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
                return this.extensionInstaller.install(extension);
            case 'update':
                return this.extensionInstaller.update(extension);
            case 'buy':
                return this.extensionPurchase.purchase(extension);
            case 'open':
                return typeof this.args.onOpen === 'function' ? this.args.onOpen(extension) : this.extensionPanel.open(extension);
        }
    }
}
