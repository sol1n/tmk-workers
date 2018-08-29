<?php

namespace App\Workers;

use App\Workers\BaseWorker;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;

use Illuminate\Support\Collection;

class EventsCreator extends BaseWorker
{
    const REPORTS_COLLECTION = 'Sections';
    const EVENTS_COLLECTION = 'Events';
    const MAIN_GROUP_ID = '1ad70d49-3efc-436c-b806-4a303aa2679c';

    /**
     * Gets events collection sections which has non-empty "reports" field
     * @return \Illuminate\Support\Collection
     */
    private function getTasks(): Collection
    {
        return Element::list(self::EVENTS_COLLECTION, $this->user->backend, [
            'take' => -1,
            'where' => [
                'reports' => [
                    '$exists' => true
                ]
            ]
        ]);
    }

    /**
     * Checks that event collection section does not have any childs and needs to be processed
     * @param  Appercode\Element $task
     * @return bool
     */
    private function needToProcess(Element $task): bool
    {
        return Element::list(self::EVENTS_COLLECTION, $this->user->backend, [
            'take' => 1,
            'where' => [
                'parentId' => $task->id
            ]
        ])->count() === 0;
    }

    /**
     * Gets reports collection element to copy
     * @param  array  $reportsIds
     * @return \Illuminate\Support\Collection
     */
    private function getReports(array $reportsIds): Collection
    {
        return Element::list(self::REPORTS_COLLECTION, $this->user->backend, [
            'take' => -1,
            'where' => [
                'id' => [
                    '$in' => $reportsIds
                ]
            ]
        ]);
    }

    /**
     * @param  array $fields
     * @return \Appercode\Element
     */
    private function createEvent(array $fields): Element
    {
        return Element::create(self::EVENTS_COLLECTION, $fields, $this->user->backend);
    }

    /**
     * @param  \Appercode\Element $event
     * @param  array  $fields
     * @return \Appercode\Element
     */
    private function saveEventEnFields(Element $event, array $fields): Element
    {
        Element::updateLanguages(self::EVENTS_COLLECTION, $event->id, $fields, $this->user->backend);
        return $event;
    }

    /**
     * Main handler
     * @return void
     */
    public function handle(): void
    {
        $tasks = $this->getTasks();

        if ($tasks->count()) {
            $this->log('Founded ' . $tasks->count() . ' sections to proccess');

            foreach ($tasks as $task) {
                $taskName = $task->fields['title'] ?? $task->id;
                $reportsToCopyIds = $task->fields['reports'] ?? [];
                
                if ($reportsToCopyIds && $this->needToProcess($task)) {
                    $reportsToCopy = $this->getReports($reportsToCopyIds);

                    $this->log('Start process section ' . $taskName . ' (' . $task->id . ') with ' . $reportsToCopy->count() . ' reports');

                    foreach ($reportsToCopy as $report) {
                        $event = $this->createEvent([
                            'beginAt' => $task->fields['beginAt'] ?? null,
                            'endAt' => $task->fields['endAt'] ?? null,
                            'description' => $report->fields['description'] ?? '',
                            'title' => $report->fields['title'],
                            'parentId' => $task->id,
                            'externalId' => $report->id,
                            'participantsIds' => $report->fields['userProfileIds'] ?? [],
                            'groupIds' => [self::MAIN_GROUP_ID]
                        ]);

                        $report->getLanguages('en');
                        
                        $this->saveEventEnFields($event, [
                            'en' => [
                                'title' => $report->languages['en']['title'] ?? '',
                                'description' => $report->languages['en']['description'] ?? ''
                            ]
                        ]);

                        $this->log('Created event for ' . $report->fields['title'] ?? '' . ' report with id: ' . $event->id . ' in ' . $taskName);

                        $task->fields['reports'] = null;
                        $task->save();
                    }
                } else {
                    $this->log('Can`t create elements in non-empty section: ' . $taskName . ' (' . $task->id . ')', 'error');
                }
            }
        }
    }
}
