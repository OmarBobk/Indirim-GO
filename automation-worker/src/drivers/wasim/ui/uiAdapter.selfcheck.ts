import assert from 'node:assert/strict';
import {
  FIXTURE_ACCESS_DENIED,
  FIXTURE_AMBIGUOUS,
  FIXTURE_LOGIN_V1,
  FIXTURE_MAINTENANCE,
  FIXTURE_ORDERS_PARTIAL,
  FIXTURE_ORDERS_V1,
  FIXTURE_PRODUCT_AMBIGUOUS_SUBMIT,
  FIXTURE_PRODUCT_MISSING_FIELD,
  FIXTURE_PRODUCT_V1,
  FIXTURE_SWAL_ACCEPTED,
  FIXTURE_SWAL_REJECTED,
  FIXTURE_UNKNOWN,
} from './fixtures.js';
import {
  countBuyControlsInHtml,
  detectWasimUiFromHtml,
  fixtureBlocksSubmit,
} from './fixtureDetect.js';
import { listWasimUiAdapters } from './registry.js';
import { WASIM_UI_FAILURE_CODES, FAILURE_CIRCUIT_HINTS } from './failures.js';
import { WASIM_UI_V1_ID } from './versions.js';
import {
  isSupplierOrderRejected,
  isSupplierOrderSuccessful,
  parseSwalPurchaseContent,
} from '../parseSwalPurchase.js';

function run(name: string, fn: () => void): void {
  fn();
  console.log(`ok - ${name}`);
}

run('registry exposes only proven wasim-ui-v1 adapter', () => {
  const adapters = listWasimUiAdapters();
  assert.equal(adapters.length, 1);
  assert.equal(adapters[0]?.adapterId, WASIM_UI_V1_ID);
});

run('detects login fixture', () => {
  const result = detectWasimUiFromHtml(FIXTURE_LOGIN_V1, '/identity/account/login');
  assert.equal(result.kind, 'login_required');
  assert.equal(fixtureBlocksSubmit(result), true);
});

run('detects product fixture and allows purchase path', () => {
  const result = detectWasimUiFromHtml(FIXTURE_PRODUCT_V1, '/customer/home/productrequest?productId=1');
  assert.equal(result.kind, 'recognized');
  assert.equal(result.purchaseCapable, true);
  assert.equal(fixtureBlocksSubmit(result), false);
});

run('detects orders fixture', () => {
  const result = detectWasimUiFromHtml(FIXTURE_ORDERS_V1, '/customer/order');
  assert.equal(result.kind, 'recognized');
  assert.equal(result.reconcileCapable, true);
  assert.equal(fixtureBlocksSubmit(result), true);
});

run('rejects unknown UI before submit', () => {
  const result = detectWasimUiFromHtml(FIXTURE_UNKNOWN, '/customer/home/productrequest');
  assert.equal(result.kind, 'unknown');
  assert.equal(result.failureCode, 'unsupported_ui');
  assert.equal(fixtureBlocksSubmit(result), true);
});

run('rejects ambiguous UI before submit', () => {
  const result = detectWasimUiFromHtml(FIXTURE_AMBIGUOUS, '/customer/home/productrequest');
  assert.equal(result.kind, 'ambiguous');
  assert.equal(fixtureBlocksSubmit(result), true);
});

run('recognizes maintenance and access denied', () => {
  assert.equal(detectWasimUiFromHtml(FIXTURE_MAINTENANCE, '/').kind, 'maintenance');
  assert.equal(detectWasimUiFromHtml(FIXTURE_ACCESS_DENIED, '/').kind, 'access_denied');
});

run('partial product / orders mark ambiguous', () => {
  assert.equal(
    detectWasimUiFromHtml(FIXTURE_PRODUCT_MISSING_FIELD, '/customer/home/productrequest').kind,
    'ambiguous',
  );
  assert.equal(
    detectWasimUiFromHtml(FIXTURE_ORDERS_PARTIAL, '/customer/order').kind,
    'ambiguous',
  );
});

run('ambiguous submit control count > 1', () => {
  assert.ok(countBuyControlsInHtml(FIXTURE_PRODUCT_AMBIGUOUS_SUBMIT) > 1);
  assert.equal(countBuyControlsInHtml(FIXTURE_PRODUCT_V1), 1);
});

run('swal accepted / rejected mapping preserved', () => {
  const accepted = parseSwalPurchaseContent(FIXTURE_SWAL_ACCEPTED);
  assert.equal(isSupplierOrderSuccessful(accepted.supplierStatus), true);
  assert.equal(accepted.supplierOrderId, 'WS-1001');

  const rejected = parseSwalPurchaseContent(FIXTURE_SWAL_REJECTED);
  assert.equal(isSupplierOrderRejected(rejected.supplierStatus, rejected.supplierReply, rejected.supplierOrderId), true);
});

run('failure taxonomy has C1.3 circuit hints without implementing circuits', () => {
  assert.ok(WASIM_UI_FAILURE_CODES.includes('unsupported_ui'));
  assert.equal(FAILURE_CIRCUIT_HINTS.unsupported_ui.immediatePauseCandidate, true);
  assert.equal(FAILURE_CIRCUIT_HINTS.uncertain_submission.noCircuit, true);
});

console.log('Wasim UI adapter fixture selfcheck passed');
