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

export function isSupplierOrderCompleted(status: string | null): boolean {
  return status?.trim().toLowerCase() === 'completed';
}

export function isSupplierOrderRejected(status: string | null, reply: string | null): boolean {
  if (isSupplierOrderCompleted(status)) {
    return false;
  }

  const normalized = status?.trim().toLowerCase() ?? '';

  return normalized === 'pending' && reply !== null && reply !== '';
}
