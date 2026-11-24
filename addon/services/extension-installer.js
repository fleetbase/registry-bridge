import Service, { inject as service } from '@ember/service';
import { INSTALLER_NAMESPACE } from '../utils/installer-job';

export const INSTALLER_ACTIONS = ['install', 'update', 'uninstall'];

/**
 * Starts installer jobs on the API (POST ~registry/v1/installer/{install,update,uninstall}). The install-jobs
 * service follows them; the modal that used to drive them is gone.
 */
export default class ExtensionInstallerService extends Service {
    @service fetch;

    /**
     * Queue a job for an extension.
     *
     * @param {String} jobAction install, update or uninstall
     * @param {Object} extension
     * @param {String} [version] a specific release (install or update)
     * @return {Promise<Object>} `{ job }`
     */
    request(jobAction, extension, version) {
        if (!INSTALLER_ACTIONS.includes(jobAction)) {
            throw new Error(`Unknown installer action "${jobAction}".`);
        }
        const payload = { extension: extension.public_id ?? extension.id };
        if (version && jobAction !== 'uninstall') {
            payload.version = version;
        }

        return this.fetch.post(`installer/${jobAction}`, payload, { namespace: INSTALLER_NAMESPACE });
    }
}
