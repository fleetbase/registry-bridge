'use strict';
const { name, fleetbase } = require('../package');

module.exports = function (environment) {
    let ENV = {
        modulePrefix: name,
        environment,
        mountedEngineRoutePrefix: getMountedEngineRoutePrefix(),
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
