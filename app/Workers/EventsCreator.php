<?php

namespace App\Workers;

use App\Workers\BaseWorker;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;

class EventsCreator extends BaseWorker
{
    const REPORTS_COLLECTION = 'Sections';
    const EVENTS_COLLECTION = 'Events';

    public function check()
    {
        $tasks = Element::list(self::EVENTS_COLLECTION, $this->user->backend, [
            'take' => -1,
            'where' => [
                'reports' => [
                    '$exists' => true
                ]
            ]
        ]);

        if ($tasks->count()) {
            $this->logger->info('Founded ' . $tasks->count() . ' sections to proccess');

            foreach ($tasks as $task) {
                $taskName = $task->fields['title'] ?? $task->id;
                $reportsToCopyIds = $task->fields['reports'] ?? [];
                $childElements = Element::list(self::EVENTS_COLLECTION, $this->user->backend, [
                    'take' => 1,
                    'where' => [
                        'parentId' => $task->id
                    ]
                ]);

                if ($reportsToCopyIds && $childElements->count() === 0) {
                    $this->logger->info('Start process section ' . $taskName . ' (' . $task->id . ')');

                    $reportsToCopy = Element::list(self::REPORTS_COLLECTION, $this->user->backend, [
                        'take' => -1,
                        'where' => [
                            'id' => [
                                '$in' => $reportsToCopyIds
                            ]
                        ]
                    ]);

                    foreach ($reportsToCopy as $report) {
                        $data = [
                            'beginAt' => $task->fields['beginAt'] ?? null,
                            'endAt' => $task->fields['endAt'] ?? null,
                            'description' => $report->fields['description'] ?? '',
                            'title' => $report->fields['title'],
                            'parentId' => $task->id,
                            'externalId' => $report->id
                        ];

                        $event = Element::create(self::EVENTS_COLLECTION, $data, $this->user->backend);
                        $this->logger->info('Created event for ' . $report->fields['title'] ?? '' . ' report with id: ' . $event->id . ' in ' . $taskName);
                    }
                } elseif ($childElements->count()) {
                    $this->logger->error('Can`t create elements in non-empty section: ' . $taskName . ' (' . $task->id . ')');
                }
            }
        }
    }
}
