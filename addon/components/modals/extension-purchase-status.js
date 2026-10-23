import Component from '@glimmer/component';
import { get } from '@ember/object';

/**
 * After a checkout redirect (canvas C6): Finalizing while the hand-off is
 * polled, then Paid (Install now), Not completed (Try again), or Still
 * processing when the webhook has not landed in time.
 */
export default class ModalsExtensionPurchaseStatusComponent extends Component {
    get phase() {
        return get(this.args.options ?? {}, 'phase') ?? 'finalizing';
    }
}
