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
    'commission_reversal_posted_title' => 'تم عكس العمولة',
    'commission_reversal_posted_message' => 'تم عكس :amount_display (:clawback_ref). تم استرداد الوحدة المرتبطة. الإيداعات المستقبلية تخفّض أي دين استرداد عمولة.',
    'commission_clawback_needs_review_title' => 'استرداد عمولة يحتاج مراجعة',
    'commission_clawback_needs_review_message' => 'تعذر ترحيل استرداد العمولة :clawback_ref تلقائيًا ويحتاج مراجعة.',
    'commission_clawback_waiver_approved_title' => 'تم التنازل عن استرداد العمولة',
    'commission_clawback_waiver_approved_debt_message' => 'تم التنازل عن :amount_display لـ :clawback_ref. قد يبقى دين استرداد حتى تُصفّيه الإيداعات أو التنازلات اللاحقة.',
    'commission_clawback_waiver_approved_cleared_message' => 'تم التنازل عن :amount_display لـ :clawback_ref. يمكن متابعة طلبات الصرف وفق قواعد الأهلية العادية.',
    'commission_clawback_dispute_opened_title' => 'استرداد عمولة قيد المراجعة',
    'commission_clawback_dispute_opened_message' => 'الاسترداد :clawback_ref قيد المراجعة الإدارية. تتوقف المعالجة غير المرحّلة حتى الحل.',
    'commission_clawback_dispute_resolved_title' => 'تم حل نزاع استرداد العمولة',
    'commission_clawback_dispute_resolved_message' => 'اكتملت مراجعة الاسترداد :clawback_ref.',
    'commission_clawback_dispute_rejected_message' => 'أُغلقت مراجعة الاسترداد :clawback_ref دون تغيير مالي.',
    'commission_clawback_dispute_accepted_waiver_message' => 'قُبلت مراجعة الاسترداد :clawback_ref كتنازل.',
    'commission_clawback_dispute_accepted_correction_message' => 'قُبلت مراجعة الاسترداد :clawback_ref كتصحيح.',
    'commission_reversal_correction_title' => 'تم تصحيح عكس العمولة',
    'commission_reversal_correction_debt_message' => 'تم تصحيح :amount_display لـ :clawback_ref. قد يبقى دين الاسترداد حتى تُصفّيه الإيداعات اللاحقة.',
    'commission_reversal_correction_cleared_message' => 'تم تصحيح :amount_display لـ :clawback_ref.',

    'salesperson_payout_requested_title' => 'طلب صرف عمولة',
    'salesperson_payout_requested_message' => ':name طلب صرفًا (#:id، المؤهل: :eligible_display). راجع طلبات الصرف.',

    'wasim_price_drift_review_title' => 'أسعار واسم تحتاج مراجعة',
    'automation_circuit_paused_title' => 'أتمتة وسيم متوقفة',
    'automation_circuit_paused_message' => 'تم إيقاف :capability (:reason). الإرسال الجديد محظور؛ طلبات المورد المُرسلة لا تُلغى.',
    'automation_circuit_probe_required_title' => 'أتمتة وسيم جاهزة للاستئناف',
    'automation_circuit_probe_required_message' => 'نجح فحص :capability. يجب على المشرف استئناف الإرسال صراحة.',
    'wasim_price_drift_review_message' => ':count منتج/منتجات لها أسعار دخول تختلف عن آخر مسح لوسيم. افتح صفحة انحراف الأسعار للمراجعة.',

    'wasim_price_reactive_flag_title' => 'تنبيه سعر واسم من التنفيذ',
    'wasim_price_reactive_flag_margin_message' => ':product (#:product_id) تم وسمه أثناء التنفيذ — الهامش غير كافٍ. راجع صفحة انحراف الأسعار.',
    'wasim_price_reactive_flag_mismatch_message' => ':product (#:product_id) سعر واسم المباشر يختلف عن سعر الدخول. راجع صفحة انحراف الأسعار.',
];
