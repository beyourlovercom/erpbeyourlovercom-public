<?php

namespace App\Modules\Traffic\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class TrafficInquiryDailyMetric extends Model
{
    protected $table = 'traffic_inquiry_daily_metrics';

    protected $fillable = [
        'business_project_id', 'metric_date', 'source_type', 'inquiries', 'qualified_inquiries',
    ];

    protected $casts = [
        'inquiries' => 'integer',
        'qualified_inquiries' => 'integer',
    ];
}
