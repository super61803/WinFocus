<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $table = 'cxs_projects_comments';

    public $timestamps = false;

    protected $fillable = [];
}
