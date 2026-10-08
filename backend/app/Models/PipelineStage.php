<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineStage extends Model
{
    use \App\Models\Concerns\Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_default_seed' => 'boolean'];
    }

    /** Default seed from CLAUDE.md "Sales pipeline". [name, colour, required template names] */
    public const DEFAULTS = [
        ['Lead', '#e8c3cc', []],
        ['Qualified', '#dba9b6', ['FICA Declaration Form']],
        ['Viewing Scheduled', '#d79aab', []],
        ['Offer Made', '#c23552', ['Offer to Purchase']],
        ['Offer Accepted', '#b22c47', ['Signed OTP']],
        ['FICA / Due Diligence', '#a3233f', ['ID copy', 'Proof of address', 'Proof of funds']],
        ['Bond Approved', '#7a1528', ['Bond grant letter']],
        ['Deed of Sale Signed', '#6a1222', ['Deed of Sale']],
        ['Registration (Transfer)', '#561020', ['Transfer documents']],
        ['Deal Closed', '#3d0b17', ['Commission invoice']],
    ];

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function documentTemplates(): BelongsToMany
    {
        return $this->belongsToMany(DocumentTemplate::class, 'pipeline_stage_documents');
    }

    public static function ordered()
    {
        return static::query()->orderBy('position');
    }

    public static function finalStage(): ?self
    {
        return static::ordered()->get()->last();
    }

    public function auditLabel(): string
    {
        return 'Pipeline stage '.$this->name;
    }
}
