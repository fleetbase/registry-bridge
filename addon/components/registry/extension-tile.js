import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isVerified, openExtensionPage } from '../../utils/store-model';

/**
 * An extension as a card (Discover shelves and the cards view): icon, name,
 * publisher, one line about it, its category and the install button. The
 * name opens the extension's page; the button acts on its own.
 *
 * Also the base of ExtensionRow, which shows the same facts as a list row.
 *
 * @argument {Object} extension
 * @argument {Object} [progress] a running install for this extension
 * @argument {Function} [onOpen] opens the extension's page (defaults to the detail panel)
 * @argument {Function} [onAction] passed to the install button
 */
export default class RegistryExtensionTileComponent extends Component {
    @service hostRouter;

    get extension() {
        return this.args.extension ?? {};
    }

    get blurb() {
        return this.extension.subtitle || this.extension.description || null;
    }

    get publisher() {
        return this.extension.publisher_name || this.extension.meta?.publisher?.name || null;
    }

    get verified() {
        return isVerified(this.extension);
    }

    get category() {
        return this.extension.category_name || this.extension.category?.name || null;
    }

    @action open() {
        if (typeof this.args.onOpen === 'function') {
            return this.args.onOpen(this.extension);
        }
        return openExtensionPage(this.hostRouter, this.extension);
    }
}
