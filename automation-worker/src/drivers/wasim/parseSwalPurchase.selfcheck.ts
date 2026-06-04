import {
  isSupplierOrderCompleted,
  isSupplierOrderRejected,
  parseSwalPurchaseContent,
} from './parseSwalPurchase.js';

const successHtml = `
<p><b>تم إرسال طلبك بنجاح </b></p>
<p> معرف الطلب: <b>12336</b></p>
<p> معرف المنتج: <b>39</b></p>
<p> السعر الإجمالي: <b>1.07692159</b></p>
<p> مصدر السعر: <b>Level</b></p>
<p> الرد: <b></b></p>
<p> حالة الطلب: <b>Completed</b></p>
`;

const rejectedHtml = `
<p> معرف الطلب: <b>999</b></p>
<p> السعر الإجمالي: <b>2.5</b></p>
<p> الرد: <b>{"replay":["20210-Crystal accounts cannot be recharged to gem users"]}</b></p>
<p> حالة الطلب: <b>pending</b></p>
`;

const success = parseSwalPurchaseContent(successHtml);

if (success.supplierOrderId !== '12336' || success.supplierEntryPrice !== 1.07692159) {
  throw new Error(`success parse failed: ${JSON.stringify(success)}`);
}

if (! isSupplierOrderCompleted(success.supplierStatus)) {
  throw new Error('expected completed status');
}

const rejected = parseSwalPurchaseContent(rejectedHtml);

if (! isSupplierOrderRejected(rejected.supplierStatus, rejected.supplierReply)) {
  throw new Error(`expected rejected: ${JSON.stringify(rejected)}`);
}

console.log('parseSwalPurchase self-check passed');
