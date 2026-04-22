<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rol extends Model
{
    protected $table = 'Roles';

    protected $primaryKey = 'RolID';

    // Desactivamos timestamps porque la tabla Roles no tiene 'created_at' ni 'updated_at'
    public $timestamps = false;

    protected $fillable = [
        'NombreRol',
        'Estado'
    ];

    
    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'RolID', 'RolID');
    }
}