import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Admin → Extensions Registry → Registry Config: the Registry Service URL, this instance's registration
 * (masked token) and a connection test.
 */
export default class RegistryAdminConfigComponent extends Component {
    @service fetch;
    @service notifications;
    @service modalsManager;
    @service intl;
    @tracked testResult;
    @tracked serviceUrl;
    @tracked savedServiceUrl;
    @tracked instanceKind;
    @tracked instance;
    @tracked registrationCode = '';
    /** Registering an instance that is already registered: the service rotates its token on the same instance. */
    @tracked isReregistering = false;

    constructor() {
        super(...arguments);
        this.getConfigValues.perform();
    }

    get isBusy() {
        return this.getConfigValues.isRunning || this.saveConfigValues.isRunning || this.testConnection.isRunning || this.registerInstance.isRunning || this.unregisterInstance.isRunning;
    }

    get isRegistered() {
        return this.instance?.registered === true;
    }

    get canRegister() {
        return !this.isBusy && Boolean(this.serviceUrl) && Boolean(this.registrationCode && this.registrationCode.trim());
    }

    /** Where this console is served; the service only returns buyers from checkout to this host. */
    get consoleOrigin() {
        return window.location.origin;
    }

    t(key, params) {
        return this.intl.t(`registry-bridge.admin.config.${key}`, params);
    }

    applyConfig(config = {}) {
        this.serviceUrl = config.service_url;
        this.savedServiceUrl = config.service_url;
        this.instanceKind = config.instance_kind;
        this.instance = config.instance ?? { registered: false };
    }

    @task *getConfigValues() {
        try {
            const config = yield this.fetch.get('registry-extensions/config', {}, { namespace: '~registry/v1' });
            this.applyConfig(config);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *saveConfigValues() {
        try {
            const config = yield this.fetch.post('registry-extensions/config', { service_url: this.serviceUrl ?? '' }, { namespace: '~registry/v1' });
            this.applyConfig(config);
            this.testResult = null;
            this.notifications.success(this.t('saved'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *testConnection() {
        this.testResult = null;

        try {
            const result = yield this.fetch.post('registry-extensions/config/test', this.serviceUrl ? { service_url: this.serviceUrl } : {}, { namespace: '~registry/v1' });
            this.testResult = result;

            if (result && result.ok) {
                this.notifications.success(this.t('result.ok-toast', { host: result.host, ms: result.latency_ms }));
            } else {
                this.notifications.warning((result && result.error) || this.t('result.failed'));
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *registerInstance() {
        try {
            const config = yield this.fetch.post(
                'registry-extensions/instance/register',
                { code: this.registrationCode.trim(), service_url: this.serviceUrl, host: this.consoleOrigin },
                { namespace: '~registry/v1' }
            );
            this.applyConfig(config);
            this.registrationCode = '';
            this.isReregistering = false;
            this.testResult = null;
            const name = config.instance?.name ?? this.t('this-instance');
            if (config.instance?.rotated) {
                this.notifications.success(this.t('reregistered-toast', { host: config.service_url, name, id: config.instance?.id ?? '' }));
            } else {
                this.notifications.success(this.t('registered-toast', { host: config.service_url, name }));
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action startReregister() {
        this.registrationCode = '';
        this.isReregistering = true;
    }

    @action cancelReregister() {
        this.registrationCode = '';
        this.isReregistering = false;
    }

    @action confirmUnregister() {
        this.modalsManager.confirm({
            title: this.t('unregister.title'),
            body: this.t('unregister.body'),
            acceptButtonText: this.t('unregister.accept'),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                await this.unregisterInstance.perform();
                modal.done();
            },
        });
    }

    @task *unregisterInstance() {
        try {
            const config = yield this.fetch.delete('registry-extensions/instance', {}, { namespace: '~registry/v1' });
            this.applyConfig(config);
            this.testResult = null;
            this.notifications.info(this.t('unregistered-toast'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
