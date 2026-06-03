<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PushRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'session_paiement_id',
        'numero_client',
        'statut',
        'provider',
        'provider_reference',
        'provider_payload',
        'pin',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'provider_payload' => 'array',
    ];

    public function sessionPaiement()
    {
        return $this->belongsTo(SessionPaiement::class);
    }

    public function estExpire(): bool
    {
        return now()->greaterThan($this->expires_at);
    }
}
