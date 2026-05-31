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
        'pin',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
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