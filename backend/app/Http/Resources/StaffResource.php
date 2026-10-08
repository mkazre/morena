<?php

namespace App\Http\Resources;

use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Staff */
class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->user->name,
            'initials' => $this->user->initials(),
            'email' => $this->user->email,
            'phone' => $this->user->phone,
            'role_title' => $this->role_title,
            'department' => $this->department,
            'branch' => $this->branch?->name,
            'branch_id' => $this->branch_id,
            'employment_type' => $this->employment_type,
            'start_date' => $this->start_date?->toDateString(),
            'ffc_number' => $this->ffc_number,
            'ffc_expiry' => $this->ffc_expiry?->toDateString(),
            'status' => $this->status,
        ];
    }
}
