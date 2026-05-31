<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Transaction extends Model
{
    use HasUuids;

    protected $table = 'transactions';
    public $timestamps = false;

    protected $fillable = [
        'session_paiement_id',
        'operateur_id',
        'reference_gateway',
        'statut',
        'numero_client',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // RG13 : interdire la modification manuelle
    public static function boot()
    {
        parent::boot();
        static::updating(function () {
            abort(403, 'Une transaction enregistrée ne peut pas être modifiée.');
        });
    }

    // Relations
    public function sessionPaiement()
    {
        return $this->belongsTo(SessionPaiement::class, 'session_paiement_id');
    }

    public function operateur()
    {
        return $this->belongsTo(Operateur::class, 'operateur_id');
    }

    public function recu()
    {
        return $this->hasOne(Recu::class, 'transaction_id');
    }

    public function notification()
    {
        return $this->hasOne(Notification::class, 'transaction_id');
    }
}