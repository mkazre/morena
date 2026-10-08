<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EmailLog extends Model
{
    const UPDATED_AT = null;

    protected $table = 'email_log';

    protected $guarded = ['id'];

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
