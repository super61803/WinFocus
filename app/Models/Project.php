<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $primaryKey = 'project_id';

    protected $table = 'cxs_projects';

    public $timestamps = false;

    protected $fillable = [];
}
