<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'Usuarios';
    protected $primaryKey = 'UsuarioID';
    public $timestamps = false;

    public function getAuthPassword()
    {
        return $this->PasswordHash;
    }

    protected $fillable = [
        'Email',
        'PasswordHash',
        'EntidadID',
        'RolID',
        'Estado',
        'EsFounder',
        'NivelFounder',
        'FechaFounder',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'PasswordHash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'EsFounder'         => 'boolean',
            'FechaFounder'      => 'datetime',
        ];
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'RolID', 'RolID');
    }
}
