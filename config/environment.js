'use strict';
const { name, fleetbase } = require('../package');

function getenv(variable, defaultValue = null) {
    return process.env[variable] !== undefined ? process.env[variable] : defaultValue;
}

module.exports = function (environment) {
    let ENV = {
        modulePrefix: name,
        environment,
        mountedEngineRoutePrefix: getMountedEngineRoutePrefix(),
        stripe: {
            publishableKey: getenv('STRIPE_KEY'),
        },
        registry: {
            selfHosted: toBoolean(getenv('SELF_HOSTED_REGISTRY', false)),
        },
    };

    return ENV;
};

function getMountedEngineRoutePrefix() {
    let mountedEngineRoutePrefix = 'extensions';
    if (fleetbase && typeof fleetbase.route === 'string') {
        mountedEngineRoutePrefix = fleetbase.route;
    }

    return `console.${mountedEngineRoutePrefix}.`;
}

function toBoolean(value) {
    switch (value) {
        case 'true':
        case '1':
        case 1:
        case true:
            return true;
        case 'false':
        case '0':
        case 0:
        case false:
        case null:
        case undefined:
        case '':
            return false;
        default:
            return false;
    }
}
