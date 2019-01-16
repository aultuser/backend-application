<?php

namespace Modules\RedmineIntegration\Entities\Repositories;

use App\Models\Property;

/**
 * Class TaskRepository
 *
 * @package Modules\RedmineIntegration\Entities\Repositories
 */
class TaskRepository
{
    /**
     * Returns Redmine ID for current task
     * @param int $taskId
     * @return string
     */
    public function getRedmineTaskId(int $taskId)
    {
        $query = Property::where([
            ['entity_id', '=', $taskId],
            ['entity_type', '=', Property::TASK_CODE],
            ['name', '=', 'REDMINE_ID']
        ]);

        if (!$query->exists()) {
            return null;
        }
        $taskRedmineIdProperty = $query->first();

        return $taskRedmineIdProperty->value;
    }

    /**
     * Mark task with id == $taskId as NEW
     *
     * Adds a specific row to properties table
     *
     * @param int $taskId
     */
    public function markAsNew(int $taskId)
    {
        Property::create([
            'entity_id'   => $taskId,
            'entity_type' => Property::TASK_CODE,
            'name'        => 'NEW',
            'value'       => 1
        ]);
    }

    /**
     * Mark task with id == $userId as NEW
     *
     * Adds a specific row to properties table
     *
     * @param int $taskId
     */
    public function markAsOld(int $taskId)
    {
        Property::where('entity_id', '=', $taskId)
            ->where('entity_type', '=', Property::TASK_CODE)
            ->where('name', '=', 'NEW')
            ->update(['value' => 0]);
    }

    /**
     * Set redmine id for task
     * @param int $taskId Task id in local system
     * @param int $taskRedmineId Task id in redmine
     */
    public function setRedmineId(int $taskId, int $taskRedmineId)
    {
        Property::create([
            'entity_id'   => $taskId,
            'entity_type' => Property::TASK_CODE,
            'name'        => 'REDMINE_ID',
            'value'       => $taskRedmineId
        ]);
    }
}