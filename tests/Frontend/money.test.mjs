import test from 'node:test';
import assert from 'node:assert/strict';
import { formatMinorUnits, money } from '../../resources/js/types.ts';

const currencies = { USD: 2, JPY: 0, KWD: 3 };
test('selecting a currency reveals saved optical receipt line amounts exactly', () => {
    assert.equal(formatMinorUnits(15699, null, currencies), null);
    assert.equal(formatMinorUnits(15699, 'USD', currencies), '156.99');
    assert.equal(formatMinorUnits(6499, 'USD', currencies), '64.99');
    assert.equal(formatMinorUnits(22198, 'USD', currencies), '221.98');
});
test('formatting respects currency precision, zero, refunds, and unknown amounts', () => {
    assert.equal(formatMinorUnits(15699, 'JPY', currencies), '15699');
    assert.equal(formatMinorUnits(15699, 'KWD', currencies), '15.699');
    assert.equal(formatMinorUnits(0, 'USD', currencies), '0.00');
    assert.equal(formatMinorUnits(-10, 'USD', currencies), '-0.10');
    assert.equal(formatMinorUnits(null, 'USD', currencies), null);
    assert.equal(formatMinorUnits(12.5, 'USD', currencies), null);
    assert.equal(formatMinorUnits(1234, 'UNKNOWN', currencies), null);
});
test('known raw amounts with unknown currency are not mislabeled as missing', () => {
    assert.equal(money({ amount: null, currency: null, total_minor: 22198 }), 'Select currency to show amount');
    assert.equal(money({ amount: null, currency: null, total_minor: null }), 'Amount unknown');
});

test('printed amounts remain visible without a currency label or assumed decimal scale', () => {
    for (const amount of ['156.99', '1200', '1.234500', '-15.25', '0']) {
        assert.equal(money({ amount, currency: null }), `${amount} · Currency unconfirmed`);
    }
    assert.equal(money({ amount: '1200', currency: 'JPY' }), 'JPY 1200');
    assert.equal(money({ amount: '1.234', currency: 'KWD' }), 'KWD 1.234');
});
