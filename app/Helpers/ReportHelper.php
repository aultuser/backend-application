<?php

namespace App\Helpers;

use App\Models\Project;
use App\Models\Screenshot;
use App\Models\Task;
use App\Models\TimeInterval;
use App\User;
use Carbon\Carbon;
use DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

class ReportHelper
{
    /**
     * @var Project
     */
    protected $project;

    /**
     * @var TimeInterval
     */
    protected $timeInterval;

    /**
     * @var User
     */
    protected $user;

    /**
     * @var Task
     */
    protected $task;

    /**
     * @var Screenshot
     */
    protected $screenshot;

    /**
     * ReportHelper constructor.
     *
     * @param  Project       $project
     * @param  TimeInterval  $timeInterval
     * @param  User          $user
     * @param  Task          $task
     * @param  Screenshot    $screenshot
     */
    public function __construct(
        Project $project,
        TimeInterval $timeInterval,
        User $user,
        Task $task,
        Screenshot $screenshot
    ) {
        $this->project = $project;
        $this->timeInterval = $timeInterval;
        $this->user = $user;
        $this->task = $task;
        $this->screenshot = $screenshot;
    }

    /**
     * @param $collection
     *
     * @return Collection
     */
    public function getProcessedProjectReportCollection($collection): Collection
    {
        $collection = $collection->groupBy('project_name');

        $resultCollection = [];
        foreach ($collection as $projectName => $items) {
            foreach ($items as $item) {
                $intervals = collect(json_decode($item->intervals, true));

                if (!array_key_exists($projectName, $resultCollection)) {
                    $resultCollection[$projectName] = [
                        'id' => $item->project_id,
                        'name' => $item->project_name,
                        'project_time' => 0,
                        'users' => [],
                    ];
                }
                if (!array_key_exists($item->user_id, $resultCollection[$projectName]['users'])) {
                    $resultCollection[$projectName]['users'][$item->user_id] = [
                        'id' => $item->user_id,
                        'full_name' => $item->user_name,
                        'tasks' => [],
                        'tasks_time' => 0,
                    ];
                }
                if (!array_key_exists($item->task_id,
                    $resultCollection[$projectName]['users'][$item->user_id]['tasks'])) {

                    $resultCollection[$projectName]['users'][$item->user_id]['tasks'][$item->task_id] = [
                        'task_name' => $item->task_name,
                        'id' => $item->task_id,
                        'duration' => 0,
                        'screenshots' => [],
                    ];
                }

                if (!array_key_exists(
                    'dates',
                    $resultCollection[$projectName]['users'][$item->user_id]['tasks'][$item->task_id])
                ) {
                    $resultCollection[$projectName]['users'][$item->user_id]['tasks'][$item->task_id]['dates'] = [];
                }

                foreach ($intervals as $interval) {
                    $taskDate = Carbon::parse($interval['start_at'])->format('Y-m-d');
                    $duration = Carbon::parse($interval['end_at'])->diffInSeconds($interval['start_at']);

                    if (!array_key_exists(
                        $taskDate,
                        $resultCollection[$projectName]['users'][$item->user_id]['tasks'][$item->task_id]['dates'])
                    ) {
                        $resultCollection[$projectName]['users'][$item->user_id]['tasks'][$item->task_id]['dates'][$taskDate] = 0;
                    }

                    $resultCollection[$projectName]['users'][$item->user_id]['tasks'][$item->task_id]
                    ['dates'][$taskDate] += $duration;
                }

                // We dont need empty screens when showing them in Project Report
                // Collapsible dropdown because we creating an object which has two fields
                // 'dates => '2019-12-24'  and 'screenshots' => [ '2019-12-24' =>[ '13:00' => screenObjects] ]
                //  If screenshots created_at is empty --> Carbon will use null and pick Current Time which will brake
                // An object fields dependency and we will see Nan 'h' Nan 'm' on frontend
                $screens = array_filter(json_decode($item->screens, true), function ($screen) {
                    return !is_null($screen['created_at']);
                });

                $screenshotsCollection = collect($screens)
                    ->groupBy(function ($screen) {
                        return Carbon::parse($screen['created_at'])->format('Y-m-d');
                    })
                    ->transform(function ($screen) {
                        return $screen->groupBy(function ($screen) {
                            return Carbon::parse($screen['created_at'])->startOfHour()->format('H:i');
                        })
                            ->sortKeys();
                    })
                    ->transform(function ($screens) {
                        foreach ($screens as $hourKey => $hourlyScreens) {
                            foreach ($hourlyScreens as $screen) {
                                $time = floor(Carbon::parse($screen['created_at'])
                                        ->format('i') / 10);

                                $result[$hourKey][$time] = $screen;
                            }
                            $result[$hourKey] = array_values($result[$hourKey]);
                        }
                        return $result;
                    })
                    ->sortKeys()
                    ->toArray();

                $resultCollection[$projectName]['users'][$item->user_id]['tasks'][$item->task_id]['screenshots'] =
                    array_merge(
                        $resultCollection[$projectName]['users'][$item->user_id]['tasks'][$item->task_id]['screenshots'],
                        $screenshotsCollection
                    );

            }
        }

        foreach ($resultCollection as &$project) {
            foreach ($project['users'] as &$user) {
                foreach ($user['tasks'] as &$task) {
                    foreach ($task['dates'] as $dateSummary) {
                        $task['duration'] += $dateSummary;
                    }
                }

                usort($user['tasks'], function ($a, $b) {
                    return $a['duration'] < $b['duration'];
                });

                /** The $task variable is already taken **/
                foreach ($user['tasks'] as $userTask) {
                    $user['tasks_time'] += $userTask['duration'];
                }

                $project['project_time'] += $user['tasks_time'];
            }

            usort($project['users'], function ($a, $b) {
                return $a['tasks_time'] < $b['tasks_time'];
            });
        }

        usort($resultCollection, function ($a, $b) {
            return $a['project_time'] < $b['project_time'];
        });

        return collect($resultCollection);
    }

    /**
     * @param $collection
     *
     * @return Collection
     */
    public function getProcessedTimeUseReportCollection($collection): Collection
    {
        $collection = $collection->groupBy('user_id');

        $resultCollection = [];
        foreach ($collection as $userID => $items) {
            foreach ($items as $item) {
                if (!array_key_exists($userID, $resultCollection)) {
                    $resultCollection[$userID] = [
                        'total_time' => 0,
                        'user'       => collect(json_decode($item->user))
                    ];
                }

                $resultCollection[$userID]['tasks'][$item->task_id] = [
                    'project_id'   => $item->project_id,
                    'name'         => $item->task_name,
                    'project_name' => $item->project_name,
                    'total_time'   => 0,
                ];

                $intervals = collect(json_decode($item->intervals, true));
                foreach ($intervals as $interval) {
                    $duration = Carbon::parse($interval['end_at'])->diffInSeconds($interval['start_at']);
                    $resultCollection[$userID]['tasks'][$item->task_id]['total_time'] += $duration;
                    $resultCollection[$userID]['total_time'] += $duration;
                }
            }
        }

        return collect($resultCollection);
    }

    /**
     * @param  array   $uids
     * @param  array   $pids
     * @param  string  $startAt
     * @param  string  $endAt
     * @param          $timezoneOffset
     * @param  array   $rawSelect
     *
     * @param  array   $bindings
     *
     * @return Builder
     */
    public function getBaseQuery(
        array $uids,
        string $startAt,
        string $endAt,
        $timezoneOffset,
        array $rawSelect = [],
        array $bindings = []
    ): Builder {
        $rawSelect = implode(', ',
            array_unique(
                array_merge($rawSelect, [
                    'projects.id as project_id',
                    'projects.name as project_name',
                    'tasks.id as task_id',
                    'tasks.task_name as task_name',
                    'users.id as user_id',
                    'users.full_name as user_name',
                ])
            )
        );

        $bindings = array_merge([$timezoneOffset], $bindings);

        return DB::table($this->getTableName('project'))
            ->selectRaw($rawSelect, [$bindings])
            ->join(
                $this->getTableName('task'),
                $this->getTableName('task', 'project_id'),
                '=',
                $this->getTableName('project', 'id')
            )
            ->join(
                $this->getTableName('timeInterval'),
                $this->getTableName('timeInterval', 'task_id'),
                '=',
                $this->getTableName('task', 'id')
            )
            ->join(
                $this->getTableName('user'),
                $this->getTableName('timeInterval', 'user_id'),
                '=',
                $this->getTableName('user', 'id')
            )
            ->where($this->getTableName('timeInterval', 'start_at'), '>=', $startAt)
            ->where($this->getTableName('timeInterval', 'end_at'), '<', $endAt)
            ->whereIn($this->getTableName('user','id'), $uids)
            ->groupBy('task_id');
    }

    /**
     * @param  array   $uids
     * @param  array   $pids
     * @param  string  $startAt
     * @param  string  $endAt
     * @param  mixed   $timezoneOffset
     *
     * @return Builder
     */
    public function getProjectReportQuery(
        array $uids,
        array $pids,
        string $startAt,
        string $endAt,
        $timezoneOffset
    ): Builder {
        $query = $this->getBaseQuery($uids, $startAt, $endAt, $timezoneOffset, [
            "JSON_ARRAYAGG(
                JSON_OBJECT(
                    'id', screenshots.id, 'path', screenshots.path, 'thumbnail_path', screenshots.thumbnail_path,
                    'created_at', screenshots.created_at
                )
            ) as screens",
            "JSON_ARRAYAGG(
                JSON_OBJECT(
                    'id', time_intervals.id, 'user_id', time_intervals.user_id, 'task_id', time_intervals.task_id,
                    'end_at', CONVERT_TZ(time_intervals.end_at, '+00:00', ?), 'start_at',
                    CONVERT_TZ(time_intervals.start_at, '+00:00', ?)
                )
            ) as intervals"
        ], [$timezoneOffset]);

        return $query->join(
                $this->getTableName('screenshot'),
                $this->getTableName('screenshot', 'time_interval_id'),
                '=',
                $this->getTableName('timeInterval', 'id')
            )->orderBy(DB::raw('ANY_VALUE('.$this->getTableName('screenshot', 'created_at').')'), 'ASC')
             ->whereIn($this->getTableName('project', 'id'), $pids);
    }

    /**
     * @param  array   $uids
     * @param  string  $startAt
     * @param  string  $endAt
     * @param  mixed   $timezoneOffset
     *
     * @return Builder
     */
    public function getTimeUseReportQuery(
        array $uids,
        string $startAt,
        string $endAt,
        $timezoneOffset
    ): Builder {
        return $this->getBaseQuery($uids, $startAt, $endAt, $timezoneOffset, [
            "JSON_OBJECT(
                'id', users.id, 'full_name', users.full_name, 'email', users.email, 'company_id',
                 users.company_id, 'avatar', users.avatar
             ) as user",
            "JSON_ARRAYAGG(
                JSON_OBJECT('id', time_intervals.id, 'user_id', time_intervals.user_id, 'task_id', 
                    time_intervals.task_id, 'end_at', CONVERT_TZ(time_intervals.end_at, '+00:00', ?),
                    'start_at', CONVERT_TZ(time_intervals.start_at, '+00:00', ?)
                    )
                ) as intervals"
        ], [$timezoneOffset]);
    }

    /**
     * @param  string       $entity
     *
     * @param  string|null  $column
     *
     * @return string
     */
    protected function getTableName(string $entity, string $column = null): string
    {
        if (!property_exists(static::class, $entity)) {
            throw new \RuntimeException("$entity does not exists on ".static::class);
        }

        $tblName = $this->{$entity}->getTable();
        return $column ? $tblName.".$column" : $tblName;
    }
}
