import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { cardModel } from '../../utils/extension-state';

/**
 * The state-driven primary action of an extension card or row: View details,
 * Install, Update to vX, or a disabled Installed.
 *
 * @argument {RegistryExtensionModel} resource
 * @argument {Function} [onOpen] replaces opening the detail panel
 * @argument {Function} [onAction] called with `(action, extension)` instead of the default action
 */
export default class ExtensionCardActionComponent extends Component {
    @service extensionPanel;
    @service extensionInstaller;
    @service abilities;
    @service intl;

    get extension() {
        return this.args.resource ?? {};
    }

    get action() {
        const cardAction = cardModel(this.extension).action;
        const disabled = cardAction.disabled === true || (cardAction.permission ? this.abilities.cannot(cardAction.permission) : false);

        return { ...cardAction, text: this.intl.t(cardAction.label.key, cardAction.label.params), disabled };
    }

    openDetails(options = {}) {
        if (typeof this.args.onOpen === 'function') {
            return this.args.onOpen(this.extension, options);
        }

        return this.extensionPanel.open(this.extension, options);
    }

    @action run() {
        const cardAction = cardModel(this.extension).action;
        if (typeof this.args.onAction === 'function') {
            return this.args.onAction(cardAction, this.extension);
        }

        switch (cardAction.kind) {
            case 'install':
                return this.extensionInstaller.install(this.extension);
            case 'update':
                return this.extensionInstaller.update(this.extension);
            case 'none':
                return;
            default:
                return this.openDetails();
        }
    }
}
