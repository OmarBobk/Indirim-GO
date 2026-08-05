<?php

return [
    'no_reason_given' => 'No reason given',

    'topup_requested_title' => 'New top-up request',
    'topup_requested_message' => 'A top-up of :amount_display (request #:id) is pending review.',

    'topup_approved_title' => 'Top-up approved',
    'topup_approved_message' => 'Your top-up of :amount_display has been approved and credited to your wallet.',

    'topup_rejected_title' => 'Top-up rejected',
    'topup_rejected_message' => 'Your top-up of :amount_display was rejected. Reason: :reason',

    'refund_requested_title' => 'New refund request',
    'refund_requested_message' => 'A refund of :amount_display (transaction #:transaction_id) is pending approval.',

    'refund_approved_title' => 'Refund approved',
    'refund_approved_message' => 'Your refund of :amount_display for order :order_number has been approved and credited to your wallet.',

    'refund_rejected_title' => 'Refund rejected',
    'refund_rejected_message' => 'Your refund of :amount_display for order :order_number was rejected.',

    'fulfillment_created_title' => 'New fulfillment',
    'fulfillment_created_message' => 'Fulfillment #:fulfillment_id for order #:order_id has been queued.',

    'fulfillment_process_failed_title' => 'Fulfillment processing failed',
    'fulfillment_process_failed_message' => 'Fulfillment #:fulfillment_id (order #:order_id) failed. Error: :error',

    'fulfillment_completed_title' => 'Order item delivered',
    'fulfillment_completed_message' => 'An item from your order (#:order_id) has been delivered.',

    'fulfillment_failed_title' => 'Order item delivery failed',
    'fulfillment_failed_message' => 'Delivery failed for an item from order #:order_id. Reason: :reason. You may request a refund.',

    'wallet_reconciled_title' => 'Wallet balance corrected',
    'wallet_reconciled_message' => 'Wallet #:wallet_id (user #:user_id) was reconciled. Stored: :stored, expected: :expected, diff: :diff.',

    'settlement_created_title' => 'Settlement batch created',
    'settlement_created_message' => 'Settlement #:settlement_id created: :amount_display from :count fulfillment(s).',

    'loyalty_tier_changed_title' => 'Loyalty tier updated',
    'loyalty_tier_changed_message' => 'Your loyalty tier has changed from :previous_tier to :new_tier.',

    'user_blocked_title' => 'Account blocked',
    'user_blocked_message' => 'Your account has been blocked. Contact support for assistance.',

    'user_unblocked_title' => 'Account unblocked',
    'user_unblocked_message' => 'Your account has been unblocked. You can log in again.',

    'payment_failed_title' => 'Payment failed',
    'payment_failed_message' => 'Payment for order :order_number could not be completed. :reason',
    'payment_failed_message_no_order' => 'Payment could not be completed. :reason',

    'bug_recorded_title' => 'New bug report',
    'bug_recorded_message' => 'Bug #:id (:scenario, :severity) was submitted.',
    'order_price_floored_title' => 'Order price hit entry-price floor',
    'order_price_floored_message' => 'Order :order_number had :count item(s) clamped to entry price to prevent loss.',

    'commission_credited_title' => 'Commission credited',
    'commission_credited_message' => ':amount_display — Your commission has been credited to your wallet.',
    'commission_reversal_posted_title' => 'Commission reversed',
    'commission_reversal_posted_message' => ':amount_display was reversed (:clawback_ref). Related fulfillment was refunded. Future credits reduce any outstanding clawback debt.',
    'commission_clawback_needs_review_title' => 'Commission clawback needs review',
    'commission_clawback_needs_review_message' => 'Clawback :clawback_ref could not be posted automatically and needs review.',
    'commission_clawback_waiver_approved_title' => 'Commission clawback waived',
    'commission_clawback_waiver_approved_debt_message' => ':amount_display was waived for :clawback_ref. Outstanding clawback debt may remain until future credits or further waivers clear it.',
    'commission_clawback_waiver_approved_cleared_message' => ':amount_display was waived for :clawback_ref. Payout requests may proceed subject to normal eligibility rules.',
    'commission_clawback_dispute_opened_title' => 'Commission clawback under review',
    'commission_clawback_dispute_opened_message' => 'Clawback :clawback_ref is under admin review. Unposted processing is paused until resolved.',
    'commission_clawback_dispute_resolved_title' => 'Commission clawback dispute resolved',
    'commission_clawback_dispute_resolved_message' => 'Review of clawback :clawback_ref is complete.',
    'commission_clawback_dispute_rejected_message' => 'Review of clawback :clawback_ref was closed with no financial change.',
    'commission_clawback_dispute_accepted_waiver_message' => 'Review of clawback :clawback_ref was accepted as a waiver.',
    'commission_clawback_dispute_accepted_correction_message' => 'Review of clawback :clawback_ref was accepted as a correction.',
    'commission_reversal_correction_title' => 'Commission reversal corrected',
    'commission_reversal_correction_debt_message' => ':amount_display was corrected for :clawback_ref. Outstanding clawback debt may remain until future credits clear it.',
    'commission_reversal_correction_cleared_message' => ':amount_display was corrected for :clawback_ref.',

    'salesperson_payout_requested_title' => 'Payout requested',
    'salesperson_payout_requested_message' => ':name requested a payout (#:id, eligible: :eligible_display). Review payout requests.',

    'wasim_price_drift_review_title' => 'Wasim prices need review',
    'automation_circuit_paused_title' => 'Wasim automation paused',
    'automation_circuit_paused_message' => ':capability paused (:reason). New dispatch is blocked; submitted supplier orders are not cancelled.',
    'automation_circuit_probe_required_title' => 'Wasim automation ready to resume',
    'automation_circuit_probe_required_message' => ':capability probe passed. An admin must explicitly resume dispatch.',
    'wasim_price_drift_review_message' => ':count product(s) have entry prices that differ from the latest Wasim scan. Open price drift to review.',

    'wasim_price_reactive_flag_title' => 'Wasim price alert from fulfillment',
    'wasim_price_reactive_flag_margin_message' => ':product (#:product_id) was flagged during fulfillment — margin blocked. Review on price drift.',
    'wasim_price_reactive_flag_mismatch_message' => ':product (#:product_id) live Wasim price differs from entry price. Review on price drift.',
];
