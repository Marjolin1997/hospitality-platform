<?php

namespace App\Services\Operations;

use App\Models\Business;

/**
 * Backward-compatible adapter.
 *
 * ManagePreparationStations is the canonical implementation. Keeping this
 * singular class as a delegate prevents legacy container bindings/imports from
 * drifting into a second preparation-station lifecycle implementation.
 */
final class ManagePreparationStation
{
    public function __construct(private readonly ManagePreparationStations $stations) {}

    public function save(Business $business, array $data, int $actorUserId): object
    {
        return $this->stations->save($business, $data, $actorUserId);
    }

    public function setStatus(Business $business, string $stationId, bool $isActive, int $actorUserId): object
    {
        return $this->stations->setStatus($business, $stationId, $isActive, $actorUserId);
    }
}
