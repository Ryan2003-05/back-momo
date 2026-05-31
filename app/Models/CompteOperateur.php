<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CompteOperateur extends Model
{
    use HasUuids;

    protected $table = 'compte_operateurs';

    protected $fillable = [
        'commercant_id',
        'operateur_id',
        'numero',
        'actif',
        'solde',
    ];

    protected $casts = [
        'actif'  => 'boolean',
        'solde'  => 'decimal:2',
    ];

    // Relations
    public function commercant()
    {
        return $this->belongsTo(Commercant::class, 'commercant_id');
    }

    public function operateur()
    {
        return $this->belongsTo(Operateur::class, 'operateur_id');
    }

    public function sessionPaiements()
    {
        return $this->hasMany(SessionPaiement::class, 'compte_operateur_id');
    }
}