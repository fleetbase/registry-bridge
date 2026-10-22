import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class RegistryAdminConfigComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked registryHost;
    @tracked registryToken;
    @tracked savedToken;
    @tracked isEditingToken = false;
    @tracked testResult;

    constructor() {
        super(...arguments);
        this.getConfigValues.perform();
    }

    get isBusy() {
        return this.getConfigValues.isRunning || this.saveConfigValues.isRunning || this.testConnection.isRunning;
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

    @task *getConfigValues() {
        try {
            const { host, token } = yield this.fetch.get('registry-extensions/config', {}, { namespace: '~registry/v1' });
            this.registryHost = host;
            this.registryToken = token;
            this.savedToken = token;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *saveConfigValues() {
        try {
            const { host, token } = yield this.fetch.post('registry-extensions/config', { host: this.registryHost, token: this.registryToken }, { namespace: '~registry/v1' });
            this.registryHost = host;
            this.registryToken = token;
            this.savedToken = token;
            this.isEditingToken = false;
            this.testResult = null;
            this.notifications.success('Registry configuration saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *testConnection() {
        this.testResult = null;

        try {
            const result = yield this.fetch.post('registry-extensions/config/test', { host: this.registryHost, token: this.registryToken }, { namespace: '~registry/v1' });
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
}
