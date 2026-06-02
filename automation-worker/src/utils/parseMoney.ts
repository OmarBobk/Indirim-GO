/**
 * Parse a money string from an input value (Western or Arabic-Indic digits).
 */
export function parseMoneyString(raw: string): number | null {
  const trimmed = raw.trim();

  if (trimmed === '') {
    return null;
  }

  const westernDigits = trimmed.replace(/[\u0660-\u0669]/g, (digit) =>
    String(digit.charCodeAt(0) - 0x0660),
  );

  const numeric = westernDigits.replace(/[^\d.,-]/g, '');

  if (numeric === '' || numeric === '-' || numeric === '.' || numeric === ',') {
    return null;
  }

  const normalized = normalizeMoneyNumeric(numeric);

  if (normalized === null) {
    return null;
  }

  const value = Number.parseFloat(normalized);

  return Number.isFinite(value) ? value : null;
}

function normalizeMoneyNumeric(numeric: string): string | null {
  const dotCount = (numeric.match(/\./g) ?? []).length;
  const commaCount = (numeric.match(/,/g) ?? []).length;

  if (dotCount === 0 && commaCount === 0) {
    return numeric;
  }

  if (dotCount === 1 && commaCount === 0) {
    return numeric;
  }

  if (commaCount === 1 && dotCount === 0) {
    return numeric.replace(',', '.');
  }

  if (dotCount > 1 && commaCount === 0) {
    return collapseGroupedSeparators(numeric, '.');
  }

  if (commaCount > 1 && dotCount === 0) {
    return collapseGroupedSeparators(numeric, ',');
  }

  if (dotCount >= 1 && commaCount >= 1) {
    const lastComma = numeric.lastIndexOf(',');
    const lastDot = numeric.lastIndexOf('.');
    const decimalSeparator = lastComma > lastDot ? ',' : '.';
    const thousandSeparator = decimalSeparator === ',' ? '.' : ',';

    return numeric
      .split(thousandSeparator)
      .join('')
      .replace(decimalSeparator, '.');
  }

  return null;
}

/**
 * Multiple grouping separators: classic thousands (1.234.567) or last separator as decimal.
 */
function collapseGroupedSeparators(numeric: string, separator: '.' | ','): string {
  const parts = numeric.split(separator);

  if (parts.length <= 1) {
    return numeric;
  }

  const [head = '', ...tail] = parts;
  const isClassicThousands =
    /^\d{1,3}$/.test(head) && tail.length > 0 && tail.every((part) => /^\d{3}$/.test(part));

  if (isClassicThousands) {
    return parts.join('');
  }

  const lastIndex = numeric.lastIndexOf(separator);
  const integerPart = numeric.slice(0, lastIndex).split(separator).join('');
  const fractionPart = numeric.slice(lastIndex + 1);

  return `${integerPart}.${fractionPart}`;
}
