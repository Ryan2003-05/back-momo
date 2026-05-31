<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SessionPaiement extends Model
{
    use HasUuids;

    protected $table = 'session_paiements';
    public $timestamps = false;

    protected $fillable = [
        'commercant_id',
        'compte_operateur_id',
        'montant',
        'libelle',
        'produits',
        'statut',
        'type_paiement',
        'expires_at',
        'created_at',
    ];

    protected $casts = [
        'montant'    => 'decimal:2',
        'produits'   => 'array',     // ← ajoute JSON automatiquement converti en tableau
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    // Relations
    public function commercant()
    {
        return $this->belongsTo(Commercant::class, 'commercant_id');
    }

    public function compteOperateur()
    {
        return $this->belongsTo(CompteOperateur::class, 'compte_operateur_id');
    }

    public function transaction()
    {
        return $this->hasOne(Transaction::class, 'session_paiement_id');
    }

    // RG8 : vérifie si la session est expirée
    public function estExpiree(): bool
    {
        return now()->isAfter($this->expires_at);
    }
}