import { test } from 'node:test';
import assert from 'node:assert/strict';

import { parseAmount, validateAmounts, validateCountries } from './ppro-eligibility_validation.js';

const bounds = { minBound: 30, maxBound: 2000000 };

test('belowMinReturnsError', () => {
  assert.deepEqual(validateAmounts({ ...bounds, min: '0.10', max: '' }), { min: 'belowMin', max: null });
});

test('aboveMaxReturnsError', () => {
  assert.deepEqual(validateAmounts({ ...bounds, min: '', max: '20000.01' }), { min: null, max: 'aboveMax' });
});

test('minGreaterThanMaxReturnsError', () => {
  assert.deepEqual(validateAmounts({ ...bounds, min: '50', max: '10' }), { min: 'minGreaterThanMax', max: null });
});

test('minGreaterThanMaxAgainstTheBoundOfAnEmptyField', () => {
  assert.deepEqual(validateAmounts({ minBound: 500, maxBound: 200000, min: '', max: '3' }), {
    min: 'minGreaterThanMax',
    max: null,
  });
});

test('boundsAreInclusive', () => {
  assert.deepEqual(validateAmounts({ ...bounds, min: '0.30', max: '20000' }), { min: null, max: null });
});

test('emptyIsValid', () => {
  assert.deepEqual(validateAmounts({ ...bounds, min: '', max: '  ' }), { min: null, max: null });
});

test('unknownBoundsOnlyCheckMinAgainstMax', () => {
  assert.deepEqual(validateAmounts({ min: '0.01', max: '999999' }), { min: null, max: null });
  assert.deepEqual(validateAmounts({ min: '10', max: '5' }), { min: 'minGreaterThanMax', max: null });
});

test('invalidAmountReturnsError', () => {
  assert.deepEqual(validateAmounts({ ...bounds, min: 'abc', max: '12.345' }), {
    min: 'invalidAmount',
    max: 'invalidAmount',
  });
});

test('noCountryReturnsError', () => {
  assert.equal(validateCountries(0), 'noCountry');
  assert.equal(validateCountries(1), null);
});

test('commaDecimalIsParsed', () => {
  assert.equal(parseAmount('12,50'), 1250);
  assert.equal(parseAmount('12.5'), 1250);
  assert.equal(parseAmount('1 500'), 150000);
  assert.equal(parseAmount('0,1'), 10);
});

test('parseAmountDistinguishesEmptyFromInvalid', () => {
  assert.equal(parseAmount(''), null);
  assert.equal(parseAmount(undefined), null);
  assert.ok(Number.isNaN(parseAmount('-5')));
  assert.ok(Number.isNaN(parseAmount('1.2.3')));
});
