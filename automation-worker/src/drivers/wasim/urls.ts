const WASIM_ORIGIN = 'https://www.wasim-store.com';
const WASIM_PRODUCT_ORIGIN = 'https://wasim-store.com';

export const WASIM_ORDERS_URL = `${WASIM_ORIGIN}/Customer/Order`;

export function resolveWasimUrl(pathOrUrl: string): string {
  const trimmed = pathOrUrl.trim();

  if (trimmed.startsWith('http://') || trimmed.startsWith('https://')) {
    return trimmed;
  }

  if (trimmed.startsWith('/')) {
    return `${WASIM_ORIGIN}${trimmed}`;
  }

  return `${WASIM_ORIGIN}/${trimmed}`;
}

export function resolveWasimProductUrl(productApi: string): string {
  const trimmed = productApi.trim();

  if (trimmed.startsWith('http://') || trimmed.startsWith('https://')) {
    return trimmed;
  }

  return `${WASIM_PRODUCT_ORIGIN}/${trimmed.replace(/^\/+/, '')}`;
}

export function isWasimHostname(hostname: string): boolean {
  return hostname === 'wasim-store.com' || hostname === 'www.wasim-store.com';
}
