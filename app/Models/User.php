<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class User extends Authenticatable implements JWTSubject
{
    use HasUuids;

    protected $table = 'users';
    public $timestamps = false;

    protected $fillable = [
        'email',
        'mot_de_passe',
        'role',
        'token_session',
    ];

    protected $hidden = [
        'mot_de_passe',
        'token_session',
    ];

    protected $casts = [
        'role' => 'string',
    ];

    // JWT
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'role' => $this->role,
        ];
    }

    // Relation
    public function commercant()
    {
        return $this->hasOne(Commercant::class, 'utilisateur_id');
    }
}