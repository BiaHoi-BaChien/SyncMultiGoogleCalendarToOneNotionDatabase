<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PasskeyCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'user_handle',
        'credential_id',
        'public_key_pem',
        'sign_count',
    ];
}
