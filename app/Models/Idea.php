<?php

namespace App\Models;

use App\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Idea extends Model
{
    use BelongsToUser, HasFactory, SoftDeletes;

    protected $fillable = ['title', 'note', 'status'];
}
