import { startDateThreeYearsAgo } from './ordersPageHelpers.js';

const date = startDateThreeYearsAgo();

if (!/^\d{4}-\d{2}-\d{2}$/u.test(date)) {
  throw new Error(`invalid start date format: ${date}`);
}

const parsed = new Date(`${date}T00:00:00Z`);
const threeYearsAgo = new Date();
threeYearsAgo.setUTCFullYear(threeYearsAgo.getUTCFullYear() - 3);

if (Math.abs(parsed.getTime() - threeYearsAgo.getTime()) > 86_400_000) {
  throw new Error(`start date not ~3 years ago: ${date}`);
}

console.log('ordersPageHelpers self-check passed');
