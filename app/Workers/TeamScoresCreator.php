<?php

namespace App\Workers;

use App\Workers\BaseWorker;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;

use Carbon\Carbon;

use Illuminate\Support\Collection;

class TeamScoresCreator extends BaseWorker
{
    const DATES = [
        '1.10.2018',
        '2.10.2018',
        '3.10.2018',
        '4.10.2018',
        '5.10.2018'
    ];

    protected function getTeams(): Collection
    {
        return Element::list('TeamStandingsTeams', $this->user->backend, [
            'take' => -1,
        ]);
    }

    protected function markEvents(array $eventsIds)
    {
        if (count($eventsIds)) {
            $unmarked = Element::list('Events', $this->user->backend, [
                'take' => -1,
                'where' => [
                    '$or' => [
                        ['checkIn' => false],
                        ['checkIn' => ['$exists' => false]]
                    ],
                    'id' => [
                        '$in' => $eventsIds
                    ]
                ],
                'include' => ['id', 'createdAt', 'updatedAt', 'ownerId']
            ])->map(function ($item) {
                return $item->id;
            })->toArray();
        }

        if (count($unmarked)) {
            Element::bulkUpdate('Events', $unmarked, [
                'checkIn' => true
            ], $this->user->backend);
        }
    }

    protected function createEventsScores(Element $team)
    {
        $existed = Element::list('TeamStandingsScores', $this->user->backend, [
            'take' => -1,
            'where' => [
                'teamId' => $team->id
            ]
        ]);

        if ($existed->count() == 0) {
            $eventsIds = array_merge($team->fields['eventsIds1'], $team->fields['eventsIds2']);
            $eventsIds = array_unique($eventsIds);
            $eventsIds = array_values($eventsIds);

            $events = Element::list('Events', $this->user->backend, [
                'take' => -1,
                'where' => [
                    'id' => [
                        '$in' => $eventsIds
                    ]
                ]
            ]);

            $eventsToCreate = [];

            $events->each(function ($event) use (&$eventsToCreate) {
                $date = Carbon::parse($event->fields['beginAt'], 'UTC')->format('j');
                $title = $event->fields['title'];
                if (isset($eventsToCreate[$title])) {
                    $existedDate = Carbon::parse($eventsToCreate[$title]->fields['beginAt'], 'UTC')->format('j');
                    if ($date > $existedDate) {
                        $eventsToCreate[$title] = $event;
                    }
                } else {
                    $eventsToCreate[$title] = $event;
                }
            });

            foreach ($eventsToCreate as $event) {
                Element::create('TeamStandingsScores', [
                    'teamId' => $team->id,
                    'eventId' => $event->id,
                    'date' => Carbon::parse($event->fields['beginAt'], 'UTC')->toAtomString(),
                    //'score' => array_random([-20, -10, 0, 10, 20])
                ], $this->user->backend);
            }
        }
    }

    public function handle()
    {
        $teams = $this->getTeams();

        $teams->each(function ($team) {
            $this->createEventsScores($team);
        });
    }
}
