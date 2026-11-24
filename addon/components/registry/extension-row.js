import RegistryExtensionTileComponent from './extension-tile';

/**
 * An extension as a list row (the list view of Discover and categories): the
 * same facts as ExtensionTile on one line, plus the plain compatibility verdict.
 *
 * @argument {Object} extension
 * @argument {Object} [progress]
 * @argument {Function} [onOpen]
 * @argument {Function} [onAction]
 * @argument {Boolean} [showVerdict] default true
 */
export default class RegistryExtensionRowComponent extends RegistryExtensionTileComponent {
    get showVerdict() {
        return this.args.showVerdict !== false;
    }
}
