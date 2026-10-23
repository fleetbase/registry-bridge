import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';

const STEP_ICONS = { done: 'check', active: 'spinner', failed: 'xmark', pending: null };

/**
 * Install, update and uninstall in one modal (canvas C5): confirm, progress
 * with the three named steps, success with the reload countdown, failure with
 * the failed stage, the server message and Retry. Driven by the
 * `extension-installer` service through the modal options.
 */
export default class ModalsExtensionInstallerComponent extends Component {
    @service modalsManager;
    @service intl;

    get options() {
        return this.args.options ?? {};
    }

    get jobAction() {
        return get(this.options, 'jobAction') ?? 'install';
    }

    get phase() {
        return get(this.options, 'phase') ?? 'confirm';
    }

    get isUninstall() {
        return this.jobAction === 'uninstall';
    }

    get stepsKey() {
        return this.isUninstall ? 'uninstall' : 'install';
    }

    get steps() {
        return (get(this.options, 'steps') ?? []).map((step, index) => ({
            ...step,
            number: index + 1,
            icon: STEP_ICONS[step.status],
            label: this.intl.t(`registry-bridge.${this.stepsKey}.steps.${step.key}`),
            detail: this.stepDetail(step.key),
        }));
    }

    stepDetail(key) {
        const packages = get(this.options, 'extension.meta.package_names') ?? {};
        if (key === 'server') {
            return packages.composer ?? null;
        }
        if (key === 'engine') {
            return packages.npm ?? null;
        }
        return null;
    }

    get cliCommand() {
        const extension = get(this.options, 'extension') ?? {};
        const target = extension.slug ?? extension.public_id;
        if (!target) {
            return null;
        }
        return this.isUninstall ? `flb uninstall ${target}` : `flb install ${target}`;
    }

    get nameMatches() {
        return (get(this.options, 'confirmName') ?? '').trim() === get(this.options, 'extension.name');
    }

    @action updateConfirmName(event) {
        const value = event?.target?.value ?? '';
        const matches = value.trim() === get(this.options, 'extension.name');
        this.modalsManager.setOptions({ confirmName: value, acceptButtonDisabled: !matches });
    }
}
