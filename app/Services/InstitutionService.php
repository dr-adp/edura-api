<?php

namespace App\Services;

use App\Models\Institution;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class InstitutionService
{
    /**
     * Get paginated institutions.
     */
    public function getAll(): LengthAwarePaginator
    {
        return Institution::latest()->paginate(10);
    }

    /**
     * Create a new institution.
     */
    public function create(array $data): Institution
    {
        return Institution::create($data);
    }

    /**
     * Update an institution.
     */
    public function update(Institution $institution, array $data): Institution
    {
        $institution->update($data);

        return $institution->fresh();
    }

    /**
     * Delete an institution.
     */
    public function delete(Institution $institution): bool
    {
        return $institution->delete();
    }
}
