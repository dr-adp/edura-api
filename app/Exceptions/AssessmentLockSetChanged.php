<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Signals that relationship discovery found rows outside the locked parent
 * set. The caller must let the current transaction roll back, then retry the
 * complete operation with fresh identities.
 */
final class AssessmentLockSetChanged extends RuntimeException
{
    /** @var list<int> */
    public readonly array $courseIds;

    /** @var list<int> */
    public readonly array $studentProfileIds;

    public function __construct(array $courseIds = [], array $studentProfileIds = [])
    {
        $this->courseIds = $this->sortedIds($courseIds);
        $this->studentProfileIds = $this->sortedIds($studentProfileIds);

        parent::__construct(
            'Assessment relationships changed while acquiring locks; retry the complete transaction.'
        );
    }

    /** @return list<int> */
    private function sortedIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
