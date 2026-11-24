import Component from '@glimmer/component';
import { action } from '@ember/object';

/**
 * Cards or list. The caller owns the value (see the `store-view` service for
 * the remembered choice).
 *
 * @argument {String} value cards | list
 * @argument {Function} onChange called with the new value
 */
export default class RegistryViewToggleComponent extends Component {
    get isCards() {
        return this.args.value !== 'list';
    }

    get isList() {
        return this.args.value === 'list';
    }

    @action pick(value) {
        if (value !== this.args.value && typeof this.args.onChange === 'function') {
            this.args.onChange(value);
        }
    }
}
