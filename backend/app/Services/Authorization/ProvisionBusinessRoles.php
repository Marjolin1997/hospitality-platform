<?php

namespace App\Services\Authorization;

use App\Models\Business;
use App\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProvisionBusinessRoles
{
    /**
     * Clone the global system role templates for a business.
     *
     * @return Collection<string, Role>
     */
    public function handle(Business $business): Collection
    {
        return DB::transaction(function () use ($business): Collection {
            $templates = Role::query()
                ->whereNull('business_id')
                ->where('is_system', true)
                ->with('permissions:id')
                ->orderBy('slug')
                ->get();

            return $templates->mapWithKeys(function (Role $template) use ($business): array {
                $role = Role::query()->updateOrCreate(
                    [
                        'business_id' => $business->getKey(),
                        'slug' => $template->slug,
                    ],
                    [
                        'name' => $template->name,
                        'is_system' => true,
                    ],
                );

                $role->permissions()->sync(
                    $template->permissions->modelKeys()
                );

                return [$role->slug => $role];
            });
        });
    }
}
