import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class RegistryAdminConfigComponent extends Component {
    @service fetch;
    @service notifications;
    @service modalsManager;
    @tracked registryHost;
    @tracked registryToken;
    @tracked savedToken;
    @tracked isEditingToken = false;
    @tracked testResult;
    @tracked serviceUrl;
    @tracked savedServiceUrl;
    @tracked apiVersion;
    @tracked instanceKind;
    @tracked instance;
    @tracked registrationCode = '';

    constructor() {
        super(...arguments);
        this.getConfigValues.perform();
    }

    get isBusy() {
        return this.getConfigValues.isRunning || this.saveConfigValues.isRunning || this.testConnection.isRunning || this.registerInstance.isRunning || this.unregisterInstance.isRunning;
    }

    get usesService() {
        return this.apiVersion === 'v2';
    }

    get isRegistered() {
        return this.instance?.registered === true;
    }

    /** A service URL typed but not saved yet; registration and the test run against it. */
    get serviceUrlChanged() {
        return (this.serviceUrl || '') !== (this.savedServiceUrl || '');
    }

    get canRegister() {
        return !this.isBusy && Boolean(this.serviceUrl) && Boolean(this.registrationCode && this.registrationCode.trim());
    }

    @action editToken() {
        this.savedToken = this.registryToken;
        this.registryToken = '';
        this.isEditingToken = true;
    }

    @action cancelEditToken() {
        this.registryToken = this.savedToken;
        this.isEditingToken = false;
    }

    applyConfig(config = {}) {
        this.registryHost = config.host;
        this.registryToken = config.token;
        this.savedToken = config.token;
        this.serviceUrl = config.service_url;
        this.savedServiceUrl = config.service_url;
        this.apiVersion = config.api_version;
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
            const config = yield this.fetch.post(
                'registry-extensions/config',
                { host: this.registryHost, token: this.registryToken, service_url: this.serviceUrl ?? '' },
                { namespace: '~registry/v1' }
            );
            this.applyConfig(config);
            this.isEditingToken = false;
            this.testResult = null;
            this.notifications.success('Registry configuration saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *testConnection() {
        this.testResult = null;

        const payload = this.serviceUrl ? { service_url: this.serviceUrl } : { host: this.registryHost, token: this.registryToken };

        try {
            const result = yield this.fetch.post('registry-extensions/config/test', payload, { namespace: '~registry/v1' });
            this.testResult = result;

            if (result && result.ok) {
                this.notifications.success(`Connected to ${result.host} in ${result.latency_ms}ms.`);
            } else {
                this.notifications.warning((result && result.error) || 'Unable to connect to the registry.');
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *registerInstance() {
        try {
            const config = yield this.fetch.post(
                'registry-extensions/instance/register',
                { code: this.registrationCode.trim(), service_url: this.serviceUrl },
                { namespace: '~registry/v1' }
            );
            this.applyConfig(config);
            this.registrationCode = '';
            this.testResult = null;
            this.notifications.success(`Registered with ${config.service_url} as ${config.instance?.name ?? 'this instance'}.`);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action confirmUnregister() {
        this.modalsManager.confirm({
            title: 'Unregister this instance?',
            body: 'This instance forgets its registry token and can no longer install or update extensions from the registry service. Installed extensions keep working. To revoke the token itself, remove the instance from your registry organization.',
            acceptButtonText: 'Unregister',
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
            this.notifications.info('This instance is no longer registered with the registry service.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
