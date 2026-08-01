<?php

declare(strict_types=1);

namespace App\Support\Commissions;

/**
 * Allowlisted clawback failure presentation + retry taxonomy (M7.2.1).
 */
final class CommissionClawbackFailurePresentation
{
    /**
     * Operational failure codes that may be retried without source repair.
     *
     * @var list<string>
     */
    public const RETRYABLE_CODES = [
        'job_exhausted',
        'stale_processing',
        'deadlock',
        'database_unavailable',
        'job_interrupted',
        'processing_timeout',
        'queue_interrupted',
    ];

    /**
     * Integrity / policy codes — never retry until source facts are corrected.
     *
     * @var list<string>
     */
    public const INTEGRITY_CODES = [
        'missing_commission',
        'invalid_commission_status',
        'salesperson_mismatch',
        'fulfillment_mismatch',
        'obligation_amount_conflict',
        'invalid_obligation_amount',
        'missing_original_credit',
        'invalid_original_credit',
        'credit_amount_mismatch',
        'wrong_wallet',
        'wrong_wallet_type',
        'conflicting_previous_reversal',
        'zero_remaining_without_reversal',
        'partial_remaining_unsupported',
        'invalid_amount',
        'policy_not_applicable',
        'unsupported_historical_source',
        'orphaned_reversal',
    ];

    public static function isRetryableCode(?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        return in_array($code, self::RETRYABLE_CODES, true);
    }

    public static function isIntegrityCode(?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        return in_array($code, self::INTEGRITY_CODES, true);
    }

    /**
     * @return array{title: string, explanation: string, category: string}
     */
    public static function present(?string $code): array
    {
        $normalized = is_string($code) ? trim($code) : '';

        if ($normalized === '') {
            return [
                'title' => __('messages.clawback_failure_none_title'),
                'explanation' => __('messages.clawback_failure_none_explanation'),
                'category' => 'none',
            ];
        }

        if (self::isRetryableCode($normalized)) {
            return [
                'title' => __('messages.clawback_failure_'.$normalized.'_title'),
                'explanation' => __('messages.clawback_failure_'.$normalized.'_explanation'),
                'category' => 'operational',
            ];
        }

        if (self::isIntegrityCode($normalized)) {
            $titleKey = 'messages.clawback_failure_'.$normalized.'_title';
            $explanationKey = 'messages.clawback_failure_'.$normalized.'_explanation';

            return [
                'title' => __($titleKey),
                'explanation' => __($explanationKey),
                'category' => 'integrity',
            ];
        }

        return [
            'title' => __('messages.clawback_failure_unknown_title'),
            'explanation' => __('messages.clawback_failure_unknown_explanation'),
            'category' => 'unknown',
        ];
    }
}
