import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { PERMISSIONS } from '../../utils/extension-state';

export const POLICIES = ['none', 'patch', 'minor'];

/**
 * Auto-update policy of an installed Registry Service extension: Off, Patch
 * releases or Minor releases (POST ~registry/v1/registry-extensions/{id}/auto-update).
 * `registry:auto-update` queues the updates on its schedule. Core services and
 * extensions not installed from the service have no policy.
 */
export default class CellAutoUpdatePolicyComponent extends Component {
    @service fetch;
    @service intl;
    @service abilities;
    @service notifications;
    @tracked saved = null;

    get extension() {
        return this.args.row ?? {};
    }

    get applicable() {
        return this.extension.core_extension !== true && typeof this.extension.auto_update === 'string';
    }

    get policy() {
        return this.saved ?? (POLICIES.includes(this.extension.auto_update) ? this.extension.auto_update : 'none');
    }

    get options() {
        return POLICIES.map((value) => ({ value, label: this.intl.t(`registry-bridge.installed.auto-update.${value}`) }));
    }

    get disabled() {
        return this.save.isRunning || this.abilities.cannot(PERMISSIONS.install);
    }

    get lastFailed() {
        const last = this.extension.auto_update_last;
        if (!last || last.status !== 'failed' || !last.version) {
            return null;
        }
        return this.intl.t('registry-bridge.installed.auto-update.last-failed', { version: last.version });
    }

    @action change(event) {
        const policy = event?.target?.value;
        if (POLICIES.includes(policy) && policy !== this.policy) {
            this.save.perform(policy, event.target);
        }
    }

    @task *save(policy, select) {
        const previous = this.policy;
        this.saved = policy;
        try {
            const response = yield this.fetch.post(`registry-extensions/${encodeURIComponent(this.extension.id)}/auto-update`, { policy }, { namespace: '~registry/v1' });
            this.saved = response?.auto_update ?? policy;
            this.notifications.success(
                this.intl.t('registry-bridge.installed.auto-update.saved', { name: this.extension.name, policy: this.intl.t(`registry-bridge.installed.auto-update.${this.saved}`) })
            );
        } catch (error) {
            this.saved = previous;
            if (select) {
                select.value = previous;
            }
            this.notifications.serverError(error);
        }
    }
}
