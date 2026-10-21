<?php

namespace App\Modules\Orders\Application;

enum StalePaymentClassification: string
{
    case RecentPending = 'recent_pending';
    case StaleUnpaid = 'stale_unpaid';
    case PendingEvidenceConflict = 'pending_evidence_conflict';
    case HoldReview = 'hold_review';
    case MissingTimestampReview = 'missing_timestamp_review';
    case NotCandidate = 'not_candidate';
}
