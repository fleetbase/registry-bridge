import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { isVerified, screenshotsOf } from '../utils/store-model';
import locateExtension from '../utils/extension-location';

export const TABS = ['overview', 'whats-new', 'support'];

/**
 * The extension page: header with the install button, screenshots,
 * Overview / What's new / Support, and the side column (the plain
 * compatibility card, facts, links, publisher). A failed install stays on
 * the page with Try again and, for whoever may see them, the details.
 */
export default class ExtensionController extends Controller {
    @service extensionPanel;
    @service installJobs;
    @service store;
    @service universe;
    @service intl;

    @tracked extension = null;
    @tracked more = [];
    @tracked error = null;
    @tracked tab = 'overview';
    @tracked confirmingRemove = false;

    get isLoading() {
        return this.load.isRunning;
    }

    get tabs() {
        return TABS.map((id) => ({ id, current: id === this.tab }));
    }

    get verified() {
        return isVerified(this.extension);
    }

    get publisher() {
        return this.extension?.publisher_name ?? this.extension?.meta?.publisher?.name ?? null;
    }

    get addsTo() {
        const targets = this.extension?.adds_to;
        return Array.isArray(targets) ? targets.map((target) => target?.name).filter(Boolean) : [];
    }

    get highlights() {
        return Array.isArray(this.extension?.highlights) ? this.extension.highlights.filter(Boolean) : [];
    }

    get setupSteps() {
        return Array.isArray(this.extension?.setup_steps) ? this.extension.setup_steps.filter(Boolean) : [];
    }

    get screenshots() {
        return screenshotsOf(this.extension);
    }

    get releaseNotes() {
        return this.extension?.meta?.latest_release?.notes ?? null;
    }

    get versions() {
        const versions = this.extension?.meta?.versions;
        return Array.isArray(versions) ? versions.slice(0, 10) : [];
    }

    get links() {
        const e = this.extension ?? {};
        return [
            ['website', e.website_url],
            ['support', e.support_url],
            ['privacy', e.privacy_policy_url],
            ['terms', e.tos_url],
            ['source', e.repo_url],
        ]
            .filter(([, url]) => typeof url === 'string' && /^https?:\/\//i.test(url))
            .map(([id, url]) => ({ id, url }));
    }

    get facts() {
        const e = this.extension ?? {};
        const t = (key) => this.intl.t(`registry-bridge.store.page.facts.${key}`);
        const languages = Array.isArray(e.languages) && e.languages.length ? e.languages.join(', ') : null;
        return [
            { label: t('version'), value: e.installed_version && e.is_installed ? e.installed_version : e.latest_version },
            { label: t('updated'), value: e.updatedAgo ?? null },
            { label: t('languages'), value: languages },
            { label: t('adds-to'), value: this.addsTo.join(', ') || null },
            { label: t('category'), value: e.category_name ?? null },
        ].filter((fact) => fact.value);
    }

    get failure() {
        return this.installJobs.failureFor(this.extension);
    }

    get canSeeDetails() {
        return this.installJobs.canSeeDetails(this.failure?.action);
    }

    /** Where the installed extension shows up in the console, as `Fleet-Ops → Settings → LocoNav`. */
    get location() {
        return this.extension?.is_installed === true ? locateExtension(this.universe, this.extension) : null;
    }

    get locationPath() {
        return this.location?.path?.join(' → ') ?? null;
    }

    get canRemove() {
        return this.extension?.is_installed === true;
    }

    @task({ restartable: true }) *load(id) {
        this.error = null;
        this.more = [];
        try {
            this.extension = yield this.extensionPanel.load(id);
            this.loadMore.perform();
        } catch (error) {
            this.error = error;
            this.extension = null;
        }
    }

    /** "More like this": the same category, without this extension. */
    @task({ restartable: true }) *loadMore() {
        const category = this.extension?.category?.id ?? this.extension?.category_uuid;
        if (!category) {
            return;
        }
        try {
            const records = yield this.store.query('registry-extension', { explore: 1, category, limit: 5 });
            this.more = records
                .slice()
                .filter((record) => record.id !== this.extension?.id)
                .slice(0, 4);
        } catch {
            this.more = [];
        }
    }

    @action selectTab(tab) {
        this.tab = TABS.includes(tab) ? tab : 'overview';
    }

    @action retry() {
        const failure = this.failure;
        this.installJobs.dismiss(this.extension);
        return this.installJobs.start(['update', 'uninstall'].includes(failure?.action) ? failure.action : 'install', this.extension);
    }

    @action dismissFailure() {
        this.installJobs.dismiss(this.extension);
    }

    /** A running uninstall: the header shows "Removing…" instead of the buttons. */
    get removal() {
        return this.installJobs.removalFor(this.extension);
    }

    get removeFailure() {
        return this.failure?.action === 'uninstall' ? this.failure : null;
    }

    /** What stops and what stays, as in the library's popover. */
    get removeEffect() {
        const name = this.extension?.name;
        const app = this.location?.path?.[0] ?? null;
        const key = this.extension?.payment_required === true ? 'paid' : app ? 'in-app' : 'default';
        return this.intl.t(`registry-bridge.store.library.remove.effect.${key}`, { name, app: app ?? '' });
    }

    @action toggleRemove() {
        this.confirmingRemove = !this.confirmingRemove;
    }

    @action cancelRemove() {
        this.confirmingRemove = false;
    }

    @action onRemoveKeydown(event) {
        if (event.key === 'Escape') {
            this.confirmingRemove = false;
        }
    }

    @action focusFirst(element) {
        element.querySelector('button')?.focus();
    }

    @action remove() {
        this.confirmingRemove = false;
        this.installJobs.dismiss(this.extension);
        return this.installJobs.start('uninstall', this.extension);
    }

    @action reload() {
        return this.load.perform(this.model?.id);
    }
}
