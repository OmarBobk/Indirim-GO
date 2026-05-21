<?php

return [
    'no_reason_given' => 'لم يُذكر سبب',

    'topup_requested_title' => 'طلب إيداع جديد',
    'topup_requested_message' => 'إيداع بمبلغ :amount_display (طلب #:id) بانتظار المراجعة.',

    'topup_approved_title' => 'تم الموافقة على الإيداع',
    'topup_approved_message' => 'تمت الموافقة على إيداعك بمبلغ :amount_display وإضافته إلى محفظتك.',

    'topup_rejected_title' => 'تم رفض الإيداع',
    'topup_rejected_message' => 'تم رفض إيداعك بمبلغ :amount_display. السبب: :reason',

    'refund_requested_title' => 'طلب استرداد جديد',
    'refund_requested_message' => 'استرداد بمبلغ :amount_display (معاملة #:transaction_id) بانتظار الموافقة.',

    'refund_approved_title' => 'تمت الموافقة على الاسترداد',
    'refund_approved_message' => 'تمت الموافقة على استردادك بمبلغ :amount_display للطلب :order_number وإضافته إلى محفظتك.',

    'refund_rejected_title' => 'تم رفض الاسترداد',
    'refund_rejected_message' => 'تم رفض استردادك بمبلغ :amount_display للطلب :order_number.',

    'fulfillment_created_title' => 'طلب شراء جديد',
    'fulfillment_created_message' => 'تنفيذ #:fulfillment_id للطلب #:order_id في قائمة الانتظار.',

    'fulfillment_process_failed_title' => 'فشل تنفيذ الطلب',
    'fulfillment_process_failed_message' => 'فشل التنفيذ #:fulfillment_id (الطلب #:order_id). الخطأ: :error',

    'fulfillment_completed_title' => 'تمت الطلبية بنجاح',
    'fulfillment_completed_message' => 'تم توصيل طلبك رقم (#:order_id).',

    'fulfillment_failed_title' => 'فشل توصيل الطلب',
    'fulfillment_failed_message' => 'فشل توصيل الطلب #:order_id. السبب: :reason. يمكنك طلب الاسترداد.',

    'wallet_reconciled_title' => 'تم تصحيح رصيد المحفظة',
    'wallet_reconciled_message' => 'تم تسوية المحفظة #:wallet_id (المستخدم #:user_id). المخزون: :stored، المتوقع: :expected، الفرق: :diff.',

    'settlement_created_title' => 'تم إنشاء دفعة تسوية',
    'settlement_created_message' => 'تم إنشاء التسوية #:settlement_id: :amount_display من :count تنفيذ(ات).',

    'loyalty_tier_changed_title' => 'تم تحديث مستوى الولاء',
    'loyalty_tier_changed_message' => 'تغير مستوى ولائك من :previous_tier إلى :new_tier.',

    'user_blocked_title' => 'تم حظر الحساب',
    'user_blocked_message' => 'تم حظر حسابك. تواصل مع الدعم للمساعدة.',

    'user_unblocked_title' => 'تم إلغاء حظر الحساب',
    'user_unblocked_message' => 'تم إلغاء حظر حسابك. يمكنك تسجيل الدخول مرة أخرى.',

    'payment_failed_title' => 'فشل الدفع',
    'payment_failed_message' => 'تعذر إتمام الدفع للطلب :order_number. :reason',
    'payment_failed_message_no_order' => 'تعذر إتمام الدفع. :reason',

    'bug_recorded_title' => 'بلاغ خطأ جديد',
    'bug_recorded_message' => 'تم إرسال بلاغ رقم :id (:scenario، :severity).',
    'order_price_floored_title' => 'تم تطبيق حد سعر التكلفة على الطلب',
    'order_price_floored_message' => 'الطلب :order_number يحتوي على :count عنصر/عناصر تم تثبيت سعرها عند سعر الدخول لمنع الخسارة.',

    'commission_credited_title' => 'تم إيداع العمولة',
    'commission_credited_message' => ':amount_display — تم إيداع عمولتك في محفظتك.',

    'salesperson_payout_requested_title' => 'طلب صرف عمولة',
    'salesperson_payout_requested_message' => ':name طلب صرفًا (#:id، المؤهل: :eligible_display). راجع طلبات الصرف.',
];
