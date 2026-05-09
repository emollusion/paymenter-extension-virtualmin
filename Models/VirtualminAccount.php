<?php

namespace Paymenter\Extensions\Servers\Virtualmin\Models;

use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks the Virtualmin account provisioned for each Paymenter service.
 *
 * Passwords are NOT stored. The account is created with a temporary password
 * that is displayed once and then discarded. A "Reset Password" action
 * allows the customer to receive a new one-time password at any time.
 * Future: SSO via Usermin session token will replace password display entirely.
 */
class VirtualminAccount extends Model
{
    protected $table = 'virtualmin_accounts';

    protected $fillable = [
        'service_id',
        'domain',
        'username',
        'usermin_url',
        'control_panel_url',
        'node_host',
        'node_port',
        'status',
        'provisioned_at',
    ];

    protected $casts = [
        'provisioned_at' => 'datetime',
    ];

    /**
     * The Paymenter service this account belongs to.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Whether the account is currently active in Virtualmin.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
