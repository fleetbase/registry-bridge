import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import compatibilityVerdict from '../../utils/compatibility';

const STEP_ICONS = { done: 'check', active: 'spinner', failed: 'xmark', pending: null };

/**
 * Install, update and uninstall in one modal (canvas C5): confirm with the
 * plain compatibility verdict, progress with a few plain steps ("Getting
 * things ready", "Downloading", "Installing", "Finishing up"), one progress
 * bar and a friendly status line, success with the reload countdown, failure
 * with one friendly sentence and Retry. The technical stages, their
 * durations, the server message and the log stay behind "Show details".
 * Driven by the `extension-installer` service through the modal options.
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
            // "Installing" reads "Updating" for an update.
            label: this.intl.t(`registry-bridge.installer.phases.${step.key === 'install' && this.jobAction === 'update' ? 'update' : step.key}`),
        }));
    }

    /** The plain "can I install this?" verdict on the confirm step (install and update). */
    get compatibility() {
        return this.isUninstall ? null : compatibilityVerdict(this.intl, get(this.options, 'extension'));
    }

    /** Whether the technical details (stages, durations, log) are offered: administrators and whoever may install. */
    get canSeeDetails() {
        return get(this.options, 'canSeeDetails') !== false;
    }

    /** The technical stages with their status and duration (`details` from the job runner). */
    get details() {
        return (get(this.options, 'details') ?? []).map((stage) => ({
            ...stage,
            icon: STEP_ICONS[stage.status] ?? 'check',
            duration: typeof stage.seconds === 'number' ? this.intl.t('registry-bridge.installer.details.seconds', { seconds: stage.seconds.toFixed(1) }) : null,
        }));
    }

    get hasDetails() {
        return this.canSeeDetails && (this.details.length > 0 || (get(this.options, 'installerError.log.length') ?? 0) > 0 || !!get(this.options, 'installerError.message'));
    }

    /**
     * The confirm step's time estimate: about two minutes (one to uninstall) when no console rebuild is
     * needed, a rebuild's much longer range when it is (`meta.install_mode` / `meta.uninstall_mode`).
     */
    get timeNote() {
        const mode = get(this.options, `extension.meta.${this.isUninstall ? 'uninstall_mode' : 'install_mode'}`);
        const key = mode === 'build' ? 'time-rebuild' : 'time';

        return this.intl.t(`registry-bridge.${this.stepsKey}.confirm.${key}`, { minutes: this.isUninstall ? 1 : 2 });
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
