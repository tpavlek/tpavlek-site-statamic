<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoDistribution extends Model
{
    protected $fillable = [
        'entry_id',
        'platform',
        'status',
        'platform_id',
        'error_message',
    ];
}
