import { module, test } from 'qunit';
import { CARD_STATES, CTA, PERMISSIONS, cardModel, cardState, detailCta, priceOf, unavailableReason } from '@fleetbase/registry-bridge-engine/utils/extension-state';

const listing = (attrs = {}, meta = {}) => ({ source: 'registry-service', is_installable: true, ...attrs, meta: { source: 'registry-service', ...meta } });
const cloudOnly = (attrs = {}) =>
    listing({ is_installable: false, ...attrs }, { hosting: 'cloud', install_blocked_code: 'hosting', install_blocked_reason: 'This extension is only available on Fleetbase Cloud.' });

module('Unit | Utility | extension-state', function () {
    test('every card state is reachable from the record fields', function (assert) {
        const records = {
            free: listing({ payment_required: false }),
            paid: listing({ payment_required: true, price: 14900 }),
            'on-sale': listing({ payment_required: true, price: 9900, sale_price: 7900, on_sale: true }),
            installed: listing({ is_installed: true }),
            update: listing({ is_installed: true, update_available: true, installed_version: '1.8.0', latest_version: '2.0.0' }),
            purchased: listing({ payment_required: true, price: 5900, is_purchased: true }),
            unavailable: cloudOnly(),
        };

        assert.deepEqual(Object.keys(records).sort(), [...CARD_STATES].sort());
        for (const [state, record] of Object.entries(records)) {
            assert.strictEqual(cardState(record), state, state);
        }
    });

    test('a sale price only counts when it is lower than the list price', function (assert) {
        assert.deepEqual(priceOf({ payment_required: true, price: 9900, sale_price: 7900, on_sale: true, currency: 'EUR' }), { free: false, amount: 7900, original: 9900, currency: 'EUR' });
        assert.strictEqual(priceOf({ payment_required: true, price: 9900, sale_price: 12000, on_sale: true }).original, null);
        assert.strictEqual(cardState({ payment_required: true, price: 9900, sale_price: 9900, on_sale: true }), 'paid');
        assert.true(priceOf({ payment_required: false, price: 9900 }).free);
    });

    test('a listing this instance cannot run says why, on the card and its badge', function (assert) {
        const model = cardModel(cloudOnly({ payment_required: true, price: 1900 }));

        assert.strictEqual(model.state, 'unavailable');
        assert.strictEqual(model.unavailableReason, 'This extension is only available on Fleetbase Cloud.');
        assert.deepEqual(model.badges[0], {
            label: { key: 'registry-bridge.card.unavailable', params: {} },
            status: 'warning',
            help: 'This extension is only available on Fleetbase Cloud.',
        });
        assert.true(
            model.badges.some((badge) => badge.label.key === 'registry-bridge.card.cloud-only'),
            'the hosting badge stays'
        );
        assert.strictEqual(model.action.kind, 'details', 'the details still open');
        assert.strictEqual(unavailableReason(cloudOnly({ is_installed: true })), null, 'an installed extension is never unavailable');
        assert.strictEqual(unavailableReason(listing({}, { install_blocked_code: 'not_registered', install_blocked_reason: 'Register.' })), null, 'registration is a page-level message');
        assert.strictEqual(cardState(listing({ is_installable: false }, { install_blocked_code: 'no_release', install_blocked_reason: 'No release.' })), 'unavailable');
    });

    test('card models carry the price badge, state badges and the footer action', function (assert) {
        const update = cardModel(listing({ is_installed: true, update_available: true, installed_version: '1.8.0', latest_version: '2.0.0' }));
        assert.deepEqual(
            update.badges.map((badge) => badge.label.key),
            ['registry-bridge.card.update', 'registry-bridge.card.installed-version']
        );
        assert.deepEqual(update.action, { kind: 'update', label: { key: 'registry-bridge.card.update-to', params: { version: '2.0.0' } }, type: 'primary', permission: PERMISSIONS.install });

        const purchased = cardModel(listing({ payment_required: true, price: 5900, is_purchased: true }));
        assert.strictEqual(purchased.priceBadge.label.key, 'registry-bridge.card.purchased');
        assert.strictEqual(purchased.action.kind, 'install');
        assert.strictEqual(
            cardModel(listing({ payment_required: true, price: 5900, is_purchased: true, is_installable: false })).action.kind,
            'details',
            'a purchased but blocked listing opens the details'
        );

        const onSale = cardModel(listing({ payment_required: true, price: 9900, sale_price: 7900, on_sale: true }));
        assert.deepEqual([onSale.priceBadge.amount, onSale.priceBadge.original], [7900, 9900]);
        assert.true(cardModel(listing({ is_installed: true })).action.disabled);
        assert.strictEqual(cardModel(listing()).action.kind, 'details');
    });

    test('trust and hosting badges follow the listing meta', function (assert) {
        const model = cardModel(listing({}, { hosting: 'self_hosted', publisher: { is_verified: true } }));
        assert.deepEqual(
            model.badges.map((badge) => badge.label.key),
            ['registry-bridge.card.verified', 'registry-bridge.card.self-hosted-only']
        );
    });

    test('the detail CTA matrix', function (assert) {
        assert.deepEqual(detailCta(listing({ latest_version: '1.2.0' })), { kind: CTA.INSTALL, version: '1.2.0' });
        assert.deepEqual(detailCta(listing(), { canInstall: false }), { kind: CTA.UNAUTHORIZED, permission: PERMISSIONS.install });
        assert.deepEqual(detailCta(listing({ is_installed: true, installed_version: '1.0.0' })), { kind: CTA.INSTALLED, version: '1.0.0' });
        assert.deepEqual(detailCta(listing({ is_installed: true, update_available: true, installed_version: '1.0.0', latest_version: '1.1.0' })), {
            kind: CTA.UPDATE,
            version: '1.1.0',
            installedVersion: '1.0.0',
        });
        assert.strictEqual(detailCta(listing({ is_installed: true, update_available: true }), { canInstall: false }).kind, CTA.UNAUTHORIZED);
        assert.deepEqual(detailCta(listing({ is_author: true, is_installed: true })), { kind: CTA.AUTHOR });

        const notRegistered = listing({ is_installable: false }, { install_blocked_code: 'not_registered', install_blocked_reason: 'Register this instance.' });
        assert.deepEqual(detailCta(notRegistered), { kind: CTA.REGISTRATION, reason: 'Register this instance.', code: 'not_registered' });
        assert.deepEqual(detailCta(cloudOnly()), { kind: CTA.BLOCKED, reason: 'This extension is only available on Fleetbase Cloud.', code: 'hosting' });
    });

    test('a paid listing is bought once the service offers checkout, with the purchase permission', function (assert) {
        const meta = { install_blocked_code: 'licence_required', install_blocked_reason: 'Purchasing through the registry service is not available yet.' };
        const paid = listing({ payment_required: true, price: 4900, is_installable: false }, meta);

        assert.deepEqual(detailCta(paid), { kind: CTA.BLOCKED, reason: meta.install_blocked_reason, code: 'licence_required' }, 'blocked with the service reason');
        assert.deepEqual(detailCta(listing({ payment_required: true, price: 4900, is_installable: false }, { ...meta, purchase_available: true })), { kind: CTA.PURCHASE });
        assert.deepEqual(detailCta(listing({ payment_required: true, price: 4900, is_installable: false }, { ...meta, purchase_available: true }), { canPurchase: false }), {
            kind: CTA.UNAUTHORIZED,
            permission: PERMISSIONS.purchase,
        });
        assert.strictEqual(detailCta(listing({ payment_required: true, price: 4900, is_purchased: true, latest_version: '1.0.0' })).kind, CTA.INSTALL, 'a purchased listing installs');
        assert.strictEqual(detailCta(cloudOnly({ payment_required: true, price: 1900 })).kind, CTA.BLOCKED, 'another hosting cannot be bought here');
    });
});
