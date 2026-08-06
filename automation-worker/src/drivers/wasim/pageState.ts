import { isWasimHostname } from './urls.js';

export function isWasimLoginPage(url: string): boolean {
  try {
    const parsed = new URL(url);

    return isWasimHostname(parsed.hostname)
      && parsed.pathname.replace(/\/$/, '').toLowerCase().includes('/identity/account/login');
  } catch {
    return false;
  }
}

export function extractProductIdFromUrl(url: string): string | null {
  try {
    return new URL(url).searchParams.get('productId');
  } catch {
    return null;
  }
}

export function isWasimProductRequestPage(url: string, targetProductUrl?: string): boolean {
  try {
    const parsed = new URL(url);

    if (!isWasimHostname(parsed.hostname)) {
      return false;
    }

    const path = parsed.pathname.replace(/\/$/, '').toLowerCase();

    if (!path.includes('/customer/home/productrequest')) {
      return false;
    }

    if (targetProductUrl === undefined) {
      return true;
    }

    const expectedProductId = extractProductIdFromUrl(targetProductUrl);
    const currentProductId = extractProductIdFromUrl(url);

    if (expectedProductId !== null && currentProductId !== null) {
      return expectedProductId === currentProductId;
    }

    return url.split('?')[0] === targetProductUrl.split('?')[0];
  } catch {
    return false;
  }
}

export function isWasimOrdersPage(url: string): boolean {
  try {
    const parsed = new URL(url);

    if (!isWasimHostname(parsed.hostname)) {
      return false;
    }

    return parsed.pathname.replace(/\/$/, '').toLowerCase().endsWith('/customer/order');
  } catch {
    return false;
  }
}
