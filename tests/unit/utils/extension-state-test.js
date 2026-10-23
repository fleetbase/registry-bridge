import { module, test } from 'qunit';
import { CARD_STATES, CTA, PERMISSIONS, cardModel, cardState, detailCta, isServiceRecord, priceOf } from '@fleetbase/registry-bridge-engine/utils/extension-state';

const service = (attrs = {}) => ({ source: 'registry-service', meta: { source: 'registry-service' }, is_installable: true, ...attrs });

module('Unit | Utility | extension-state', function () {
    test('every card state is reachable from the record fields', function (assert) {
        const records = {
            free: { payment_required: false },
            paid: { payment_required: true, price: 14900 },
            'on-sale': { payment_required: true, price: 9900, sale_price: 7900, on_sale: true },
            installed: { is_installed: true },
            update: { is_installed: true, update_available: true, installed_version: '1.8.0', latest_version: '2.0.0' },
            purchased: { payment_required: true, price: 5900, is_purchased: true },
            'self-managed': { self_managed: true },
            'in-review': { is_author: true, status: 'awaiting_review' },
            rejected: { is_author: true, status: 'rejected' },
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

    test('card models carry the price badge, state badges and the footer action', function (assert) {
        const update = cardModel({ is_installed: true, update_available: true, installed_version: '1.8.0', latest_version: '2.0.0' });
        assert.deepEqual(
            update.badges.map((badge) => badge.label.key),
            ['registry-bridge.card.update', 'registry-bridge.card.installed-version']
        );
        assert.deepEqual(update.action, { kind: 'update', label: { key: 'registry-bridge.card.update-to', params: { version: '2.0.0' } }, type: 'primary', permission: PERMISSIONS.install });

        const purchased = cardModel({ payment_required: true, price: 5900, is_purchased: true });
        assert.strictEqual(purchased.priceBadge.label.key, 'registry-bridge.card.purchased');
        assert.strictEqual(purchased.action.kind, 'install');
        assert.strictEqual(
            cardModel({ payment_required: true, price: 5900, is_purchased: true, is_installable: false }).action.kind,
            'details',
            'a purchased but blocked listing opens the details instead'
        );

        const onSale = cardModel({ payment_required: true, price: 9900, sale_price: 7900, on_sale: true });
        assert.deepEqual([onSale.priceBadge.amount, onSale.priceBadge.original], [7900, 9900]);

        assert.deepEqual(cardModel({ is_installed: true }).action.disabled, true);
        assert.strictEqual(cardModel({ self_managed: true }).action.kind, 'self-managed');
        assert.strictEqual(cardModel({ is_author: true, status: 'rejected' }).action.kind, 'portal');
        assert.strictEqual(cardModel({ is_author: true, status: 'rejected' }).badges[0].status, 'error');
        assert.strictEqual(cardModel({}).action.kind, 'details');
    });

    test('trust and hosting badges follow the listing meta', function (assert) {
        const model = cardModel(service({ meta: { source: 'registry-service', hosting: 'self_hosted', publisher: { is_verified: true } } }));
        assert.deepEqual(
            model.badges.map((badge) => badge.label.key),
            ['registry-bridge.card.verified', 'registry-bridge.card.self-hosted-only']
        );
    });

    test('records from the Registry Service are recognised by source or meta', function (assert) {
        assert.true(isServiceRecord({ source: 'registry-service' }));
        assert.true(isServiceRecord({ meta: { source: 'registry-service' } }));
        assert.false(isServiceRecord({ source: 'registry' }));
    });

    test('the detail CTA matrix', function (assert) {
        const cta = (record, context) => detailCta(record, context);

        assert.deepEqual(cta(service({ latest_version: '1.2.0' })), { kind: CTA.INSTALL, version: '1.2.0' });
        assert.deepEqual(cta(service(), { canInstall: false }), { kind: CTA.UNAUTHORIZED, permission: PERMISSIONS.install });
        assert.deepEqual(cta(service({ is_installed: true, installed_version: '1.0.0' })), { kind: CTA.INSTALLED, version: '1.0.0' });
        assert.deepEqual(cta(service({ is_installed: true, update_available: true, installed_version: '1.0.0', latest_version: '1.1.0' })), {
            kind: CTA.UPDATE,
            version: '1.1.0',
            installedVersion: '1.0.0',
        });
        assert.strictEqual(cta(service({ is_installed: true, update_available: true }), { canInstall: false }).kind, CTA.UNAUTHORIZED);
        assert.deepEqual(cta(service({ is_author: true, is_installed: true })), { kind: CTA.AUTHOR });
        assert.deepEqual(cta({ self_managed: true }), { kind: CTA.SELF_MANAGED });

        const notRegistered = service({
            is_installable: false,
            meta: { source: 'registry-service', install_blocked_code: 'not_registered', install_blocked_reason: 'Register this instance.' },
        });
        assert.deepEqual(cta(notRegistered), { kind: CTA.REGISTRATION, reason: 'Register this instance.', code: 'not_registered' });

        const hosting = service({ is_installable: false, meta: { source: 'registry-service', install_blocked_code: 'hosting', install_blocked_reason: 'Cloud only.' } });
        assert.deepEqual(cta(hosting), { kind: CTA.BLOCKED, reason: 'Cloud only.', code: 'hosting' });
    });

    test('a paid listing is bought through the service only once it offers checkout, and needs the purchase permission', function (assert) {
        const meta = { source: 'registry-service', install_blocked_code: 'licence_required', install_blocked_reason: 'Purchasing through the registry service is not available yet.' };
        const paid = service({ payment_required: true, price: 4900, is_installable: false, meta });

        assert.deepEqual(detailCta(paid), { kind: CTA.BLOCKED, reason: meta.install_blocked_reason, code: 'licence_required' }, 'blocked with the service reason');
        assert.deepEqual(detailCta({ ...paid, meta: { ...meta, purchase_available: true } }), { kind: CTA.PURCHASE, legacy: false });
        assert.deepEqual(detailCta({ ...paid, meta: { ...meta, purchase_available: true } }, { canPurchase: false }), { kind: CTA.UNAUTHORIZED, permission: PERMISSIONS.purchase });
        assert.deepEqual(detailCta({ payment_required: true, price: 4900, source: 'registry' }), { kind: CTA.PURCHASE, legacy: true }, 'cloud-catalog records keep the embedded checkout');
        assert.strictEqual(detailCta(service({ payment_required: true, price: 4900, is_purchased: true, latest_version: '1.0.0' })).kind, CTA.INSTALL, 'a purchased listing installs');
    });
});
