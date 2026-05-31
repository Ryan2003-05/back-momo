<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Operateur extends Model
{
    use HasUuids;

    protected $table = 'operateurs';
    public $timestamps = false;

    protected $fillable = [
        'nom',
        'actif',
    ];

    protected $casts = [
        'actif' => 'boolean',
    ];

    // Relations
    public function compteOperateurs()
    {
        return $this->hasMany(CompteOperateur::class, 'operateur_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'operateur_id');
    }
}