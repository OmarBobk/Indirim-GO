import { parseMoneyString } from '../../utils/parseMoney.js';

export type ParsedPurchaseResponse = {
  supplierOrderId: string | null;
  supplierProductId: string | null;
  supplierEntryPrice: number | null;
  supplierStatus: string | null;
  supplierReply: string | null;
};

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function extractBoldValue(html: string, label: string): string | null {
  const pattern = new RegExp(
    `${escapeRegExp(label)}\\s*[:：]?\\s*<b>([^<]*)</b>`,
    'iu',
  );
  const match = html.match(pattern);

  if (!match?.[1]) {
    return null;
  }

  const value = match[1].trim();

  return value === '' ? null : value;
}

function extractLineValue(text: string, label: string): string | null {
  const lines = text.split(/\r?\n/);

  for (const line of lines) {
    if (!line.includes(label)) {
      continue;
    }

    const pattern = new RegExp(`${escapeRegExp(label)}\\s*[:：]?\\s*(.+)$`, 'u');
    const match = line.match(pattern);

    if (!match?.[1]) {
      continue;
    }

    const value = match[1].trim();

    return value === '' ? null : value;
  }

  return null;
}

function stripHtmlTags(value: string): string {
  return value.replace(/<[^>]+>/g, '').trim();
}

function readField(htmlOrText: string, label: string): string | null {
  const fromBold = extractBoldValue(htmlOrText, label);

  if (fromBold !== null) {
    return fromBold;
  }

  const plain = stripHtmlTags(htmlOrText);

  return extractLineValue(plain, label);
}

export function parseSwalPurchaseContent(htmlOrText: string): ParsedPurchaseResponse {
  const supplierOrderId = readField(htmlOrText, 'معرف الطلب');
  const supplierProductId = readField(htmlOrText, 'معرف المنتج');
  const priceRaw = readField(htmlOrText, 'السعر الإجمالي');
  const supplierStatus = readField(htmlOrText, 'حالة الطلب');
  const supplierReply = readField(htmlOrText, 'الرد');

  const supplierEntryPrice = priceRaw !== null ? parseMoneyString(priceRaw) : null;

  return {
    supplierOrderId,
    supplierProductId,
    supplierEntryPrice,
    supplierStatus,
    supplierReply,
  };
}

export function normalizeSupplierOrderStatus(status: string | null): string {
  if (status === null || status.trim() === '') {
    return '';
  }

  return status.trim().toLowerCase().replace(/\s+/g, '_');
}

/**
 * Wasim success statuses: immediate complete or accepted and processing asynchronously.
 */
export function isSupplierOrderSuccessful(status: string | null): boolean {
  const normalized = normalizeSupplierOrderStatus(status);

  if (normalized === '') {
    return false;
  }

  if (normalized === 'completed' || normalized === 'processing_ok_wait') {
    return true;
  }

  return normalized.includes('processing_ok');
}

/** @deprecated Use isSupplierOrderSuccessful — kept as alias for callers */
export function isSupplierOrderCompleted(status: string | null): boolean {
  return isSupplierOrderSuccessful(status);
}

export function isSupplierOrderRejected(status: string | null, reply: string | null): boolean {
  if (isSupplierOrderSuccessful(status)) {
    return false;
  }

  const normalized = normalizeSupplierOrderStatus(status);

  if (normalized !== 'pending' || reply === null || reply === '') {
    return false;
  }

  return ! isSupplierRateLimitedReply(reply);
}

export function isSupplierRateLimitedReply(reply: string): boolean {
  const text = reply.toLowerCase();

  return text.includes('نفس الدقيقة')
    || text.includes('اكثر من 1 طلب')
    || text.includes('أكثر من 1 طلب');
}
