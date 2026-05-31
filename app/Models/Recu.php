<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Recu extends Model
{
    use HasUuids;

    protected $table = 'recus';
    public $timestamps = false;

    protected $fillable = [
        'transaction_id',
        'reference',
        'montant',
        'date_emission',
    ];

    protected $casts = [
        'montant'       => 'decimal:2',
        'date_emission' => 'datetime',
    ];

    // Relation
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
}