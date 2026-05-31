<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A managed website. Links a customer to their hosting subscription,
 * domain and build project, and carries the cPanel credentials + the
 * usage / PageSpeed / WordPress telemetry the sync jobs populate.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $name
 * @property string $url
 * @property int|null $customer_product_id
 * @property int|null $domain_id
 * @property int|null $project_id
 * @property string|null $cpanel_username
 * @property string|null $cpanel_token
 * @property string|null $cpanel_server
 * @property bool $whm_managed
 * @property int|null $disk_used_mb
 * @property int|null $disk_quota_mb
 * @property int|null $email_accounts_count
 * @property int|null $email_accounts_quota
 * @property int|null $bandwidth_used_mb
 * @property int|null $bandwidth_quota_mb
 * @property Carbon|null $usage_checked_at
 * @property int|null $mainwp_site_id
 * @property string|null $wp_version
 * @property string|null $php_version
 * @property int $plugins_total
 * @property int $plugins_outdated
 * @property int $themes_outdated
 * @property Carbon|null $last_backup_at
 * @property int|null $pagespeed_mobile
 * @property int|null $pagespeed_desktop
 * @property string|null $pagespeed_lcp
 * @property string|null $pagespeed_cls
 * @property string|null $pagespeed_fcp
 * @property int|null $pagespeed_tbt
 * @property array<string, mixed>|null $pagespeed_data
 * @property Carbon|null $pagespeed_checked_at
 * @property string|null $ga4_property_id
 * @property int|null $monthly_visitors
 * @property Carbon|null $analytics_updated_at
 * @property string $status
 * @property string|null $notes
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $disk_percent
 * @property-read string|null $pagespeed_grade
 * @property-read string $health_status
 * @property-read Customer|null $customer
 * @property-read CustomerProduct|null $customerProduct
 * @property-read Domain|null $domain
 * @property-read Project|null $project
 * @property-read User $createdBy
 */
class Website extends Model
{
    protected $table = 'websites';

    protected $fillable = [
        'customer_id',
        'name',
        'url',
        'customer_product_id',
        'domain_id',
        'project_id',
        'cpanel_username',
        'cpanel_token',
        'cpanel_server',
        'whm_managed',
        'disk_used_mb',
        'disk_quota_mb',
        'email_accounts_count',
        'email_accounts_quota',
        'bandwidth_used_mb',
        'bandwidth_quota_mb',
        'usage_checked_at',
        'mainwp_site_id',
        'wp_version',
        'php_version',
        'plugins_total',
        'plugins_outdated',
        'themes_outdated',
        'last_backup_at',
        'pagespeed_mobile',
        'pagespeed_desktop',
        'pagespeed_lcp',
        'pagespeed_cls',
        'pagespeed_fcp',
        'pagespeed_tbt',
        'pagespeed_data',
        'pagespeed_checked_at',
        'ga4_property_id',
        'monthly_visitors',
        'analytics_updated_at',
        'status',
        'notes',
        'created_by',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['disk_percent', 'pagespeed_grade', 'health_status'];

    protected function casts(): array
    {
        return [
            'whm_managed' => 'boolean',
            'pagespeed_data' => 'array',
            'pagespeed_checked_at' => 'datetime',
            'usage_checked_at' => 'datetime',
            'last_backup_at' => 'datetime',
            'analytics_updated_at' => 'datetime',
            // Never stored in plaintext — Laravel's built-in encrypted cast.
            'cpanel_token' => 'encrypted',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerProduct(): BelongsTo
    {
        return $this->belongsTo(CustomerProduct::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getDiskPercentAttribute(): ?int
    {
        if (! $this->disk_used_mb || ! $this->disk_quota_mb) {
            return null;
        }

        return (int) round(($this->disk_used_mb / $this->disk_quota_mb) * 100);
    }

    public function getPagespeedGradeAttribute(): ?string
    {
        if (! $this->pagespeed_mobile) {
            return null;
        }

        return match (true) {
            $this->pagespeed_mobile >= 90 => 'good',
            $this->pagespeed_mobile >= 50 => 'needs-improvement',
            default => 'poor',
        };
    }

    /**
     * Aggregated health across disk, plugins, performance and status.
     * critical wins (suspended / disk >90%), else warning, else healthy.
     */
    public function getHealthStatusAttribute(): string
    {
        $issues = [];
        $disk = $this->disk_percent;

        if ($disk !== null && $disk > 90) {
            $issues[] = 'disk_critical';
        } elseif ($disk !== null && $disk > 80) {
            $issues[] = 'disk_warning';
        }
        if ($this->plugins_outdated > 0) {
            $issues[] = 'plugins_outdated';
        }
        if ($this->pagespeed_mobile !== null && $this->pagespeed_mobile < 50) {
            $issues[] = 'performance_poor';
        }
        if ($this->status === 'suspended') {
            $issues[] = 'suspended';
        }

        if (empty($issues)) {
            return 'healthy';
        }
        if (in_array('disk_critical', $issues, true) || in_array('suspended', $issues, true)) {
            return 'critical';
        }

        return 'warning';
    }
}
