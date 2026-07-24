import { parseMoneyString } from './parseMoney.js';

type Case = { input: string; expected: number | null };

const cases: Case[] = [
  { input: '1.774496193414', expected: 1.774496193414 },
  { input: '2.3', expected: 2.3 },
  { input: '1,77', expected: 1.77 },
  { input: '1.234.567', expected: 1234567 },
  { input: '1,234,567', expected: 1234567 },
  { input: '12.50', expected: 12.5 },
  { input: '', expected: null },
];

for (const { input, expected } of cases) {
  const got = parseMoneyString(input);

  if (got !== expected && !(got !== null && expected !== null && Math.abs(got - expected) < 1e-9)) {
    throw new Error(`parseMoneyString("${input}") => ${got}, expected ${expected}`);
  }
}

console.log(`parseMoney self-check: ${cases.length} cases passed`);
