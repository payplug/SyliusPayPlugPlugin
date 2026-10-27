const AMOUNT_PATTERN = /^\d+(\.\d{1,2})?$/;

/**
 * Euros typed by the merchant to cents: null when empty, NaN when not a positive amount with at
 * most two decimals. Both "." and "," are accepted as the decimal separator.
 */
export function parseAmount(value) {
  const normalised = String(value ?? '')
    .replace(/[\s  ]/g, '')
    .replace(',', '.');

  if (normalised === '') {
    return null;
  }

  if (!AMOUNT_PATTERN.test(normalised)) {
    return Number.NaN;
  }

  return Math.round(Number.parseFloat(normalised) * 100);
}

const isKnown = (amount) => amount !== null && amount !== undefined && !Number.isNaN(amount);

/**
 * Same rules as the server-side validator, with the PayPlug bounds in cents (undefined when
 * unknown). Returns the message key to show under each field, or null.
 */
export function validateAmounts({ min, max, minBound, maxBound }) {
  const minCents = parseAmount(min);
  const maxCents = parseAmount(max);
  const errors = { min: null, max: null };

  if (Number.isNaN(minCents)) {
    errors.min = 'invalidAmount';
  } else if (isKnown(minCents) && isKnown(minBound) && minCents < minBound) {
    errors.min = 'belowMin';
  }

  if (Number.isNaN(maxCents)) {
    errors.max = 'invalidAmount';
  } else if (isKnown(maxCents) && isKnown(maxBound) && maxCents > maxBound) {
    errors.max = 'aboveMax';
  }

  const effectiveMin = isKnown(minCents) ? minCents : minBound;
  const effectiveMax = isKnown(maxCents) ? maxCents : maxBound;
  if (errors.min === null && errors.max === null && isKnown(effectiveMin) && isKnown(effectiveMax)) {
    if (effectiveMin > effectiveMax) {
      errors.min = 'minGreaterThanMax';
    }
  }

  return errors;
}

export function validateCountries(selectedCount) {
  return selectedCount === 0 ? 'noCountry' : null;
}
