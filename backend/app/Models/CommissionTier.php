<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionTier extends Model
{
    use \App\Models\Concerns\Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'min_volume' => 'float',
            'agent_split_percent' => 'float',
            'agency_split_percent' => 'float',
        ];
    }

    /** Short label used in the UI, e.g. "Senior (70/30)". */
    public function splitLabel(): string
    {
        return strtok($this->name, ' /').' ('.(int) $this->agent_split_percent.'/'.(int) $this->agency_split_percent.')';
    }

    public function auditLabel(): string
    {
        return 'Commission tier '.$this->name;
    }
}
