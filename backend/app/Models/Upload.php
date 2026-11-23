<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Upload extends Model
{
    //
    protected $fillable = [
    'file',
    'jobDescription',
    'status',
    'analysis',
    ];

      protected $casts = [
        'analysis' => 'array',
    ];
}
