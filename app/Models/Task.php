<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $primaryKey = 'task_id';

    protected $table = 'cxs_projects_tasks';

    public $timestamps = false;

    protected $fillable = [];
}
