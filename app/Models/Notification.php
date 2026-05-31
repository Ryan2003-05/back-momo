<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Notification extends Model
{
    use HasUuids;

    protected $table = 'notifications';
    public $timestamps = false;

    protected $fillable = [
        'commercant_id',
        'transaction_id',
        'message',
        'lue',
        'created_at',
    ];

    protected $casts = [
        'lue'        => 'boolean',
        'created_at' => 'datetime',
    ];

    // Relations
    public function commercant()
    {
        return $this->belongsTo(Commercant::class, 'commercant_id');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
}
