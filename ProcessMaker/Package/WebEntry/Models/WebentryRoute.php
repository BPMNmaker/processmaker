<?php

namespace ProcessMaker\Package\WebEntry\Models;

use Illuminate\Database\Eloquent\Model;

class WebentryRoute extends Model
{
    protected $connection = 'processmaker';

    protected $table = 'webentry_routes';

    protected $guarded = [];

    protected $casts = [
        'params' => 'array',
        'is_task_webentry' => 'boolean',
    ];
}