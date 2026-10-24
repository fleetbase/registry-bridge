import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

/**
 * The cutover freeze notice at the top of every Extensions page: while the registry bridge is in maintenance
 * (`php artisan registry:maintenance on`), installing, updating, uninstalling and buying are paused.
 */
export default class RegistryMaintenanceBannerComponent extends Component {
    @service registryContext;
}
