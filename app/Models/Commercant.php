<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Commercant extends Model
{
    use HasUuids;

    protected $table = 'commercants';

    protected $fillable = [
        'utilisateur_id',
        'nom',
        'prenom',
        'nom_entreprise',
        'telephone',
        'ifu',
        'type_commerce',
        'ville',
    ];

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    public function compteOperateurs()
    {
        return $this->hasMany(CompteOperateur::class, 'commercant_id');
    }

    public function sessionPaiements()
    {
        return $this->hasMany(SessionPaiement::class, 'commercant_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'commercant_id');
    }
}