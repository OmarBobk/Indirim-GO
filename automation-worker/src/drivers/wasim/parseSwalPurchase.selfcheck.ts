import {
  isSupplierOrderPendingReconcile,
  isSupplierOrderRejected,
  isSupplierOrderSuccessful,
  isSupplierRateLimitedReply,
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
<p> السعر الإجمالي: <b>2.5</b></p>
<p> الرد: <b>{"replay":["20210-Crystal accounts cannot be recharged to gem users"]}</b></p>
<p> حالة الطلب: <b>pending</b></p>
`;

const insufficientBalanceHtml = `
<p><b>تم إرسال طلبك بنجاح </b></p>
<p> معرف الطلب: <b>15586</b></p>
<p> معرف المنتج: <b>2</b></p>
<p> السعر الإجمالي: <b>26.03091728061552</b></p>
<p> مصدر السعر: <b>source</b></p>
<p> الرد: <b>رصيدك غير كافي</b></p>
<p> حالة الطلب: <b>Pending</b></p>
`;

const inactiveProductHtml = `
<p> معرف الطلب: <b>15587</b></p>
<p> الرد: <b>المنتج غير نشط , لا يمكنك شراءه</b></p>
<p> حالة الطلب: <b>pending</b></p>
`;

const success = parseSwalPurchaseContent(successHtml);

if (success.supplierOrderId !== '12336' || success.supplierEntryPrice !== 1.07692159) {
  throw new Error(`success parse failed: ${JSON.stringify(success)}`);
}

if (! isSupplierOrderSuccessful(success.supplierStatus)) {
  throw new Error('expected completed status');
}

const processingHtml = `
<p> معرف الطلب: <b>55501</b></p>
<p> السعر الإجمالي: <b>1.5</b></p>
<p> الرد: <b></b></p>
<p> حالة الطلب: <b>Processing_OK_wait</b></p>
`;

const processing = parseSwalPurchaseContent(processingHtml);

if (! isSupplierOrderSuccessful(processing.supplierStatus) || processing.supplierOrderId !== '55501') {
  throw new Error(`processing_ok_wait parse failed: ${JSON.stringify(processing)}`);
}

const rateLimitReply = 'لا يمكن الشراء اكثر من 1 طلب في نفس الدقيقة لنفس الايدي.';

if (! isSupplierRateLimitedReply(rateLimitReply)) {
  throw new Error('expected rate limit reply');
}

const rejected = parseSwalPurchaseContent(rejectedHtml);

if (! isSupplierOrderRejected(rejected.supplierStatus, rejected.supplierReply, rejected.supplierOrderId)) {
  throw new Error(`expected rejected without order id: ${JSON.stringify(rejected)}`);
}

const insufficientBalance = parseSwalPurchaseContent(insufficientBalanceHtml);

if (! isSupplierOrderPendingReconcile(insufficientBalance.supplierStatus, insufficientBalance.supplierOrderId)) {
  throw new Error(`expected pending reconcile: ${JSON.stringify(insufficientBalance)}`);
}

if (isSupplierOrderRejected(
  insufficientBalance.supplierStatus,
  insufficientBalance.supplierReply,
  insufficientBalance.supplierOrderId,
)) {
  throw new Error('insufficient balance with order id must not be treated as rejected');
}

const inactiveProduct = parseSwalPurchaseContent(inactiveProductHtml);

if (! isSupplierOrderPendingReconcile(inactiveProduct.supplierStatus, inactiveProduct.supplierOrderId)) {
  throw new Error(`expected inactive product pending reconcile: ${JSON.stringify(inactiveProduct)}`);
}

console.log('parseSwalPurchase self-check passed');
