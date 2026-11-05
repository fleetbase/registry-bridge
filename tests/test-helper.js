import Application from 'dummy/app';
import config from 'dummy/config/environment';
import * as QUnit from 'qunit';
import { setApplication } from '@ember/test-helpers';
import { setup } from 'qunit-dom';
import { start } from 'ember-qunit';

// @fleetbase/ember-core builds API URLs from `config.API.host`, defaulting to the page's
// protocol and hostname without the port. Point it at the test server so a request that
// slips past the stubs gets a 404 instead of a refused connection on port 80.
config.API = { ...config.API, host: window.location.origin };

setApplication(Application.create(config.APP));

setup(QUnit.assert);

start();
