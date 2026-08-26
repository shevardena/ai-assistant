<?php

namespace App\Http\Requests;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Services\Teams\TeamAuthorizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBillingPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->route('current_team');

        return $team instanceof Team
            && $this->user() !== null
            && app(TeamAuthorizationService::class)->can($this->user(), $team, TeamPermission::BillingManage);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['plan_key' => ['required', 'string', Rule::in(['starter', 'pro', 'business'])]];
    }
}
