import Controller from '@ember/controller';
import config from '../config/environment';

export default class ApplicationController extends Controller {
    get selfHostedRegistry() {
        return config.registry.selfHosted === true;
    }
}
