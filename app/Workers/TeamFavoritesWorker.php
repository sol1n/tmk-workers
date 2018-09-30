<?php

namespace App\Workers;

use App\Workers\BaseWorker;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;

use Carbon\Carbon;

use Illuminate\Support\Collection;

class TeamFavoritesWorker extends BaseWorker
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

    protected function createFavoriteItem(array $eventsIds, int $userId)
    {
        $existedItems = Element::list('Favorites', $this->user->backend, [
            'take' => -1,
            'where' => [
                'userId' => $userId,
                'objectId' => [
                    '$in' => $eventsIds
                ],
                'isMandatory' => true
            ]
        ])->mapWithKeys(function ($item) {
            return [$item->fields['objectId'] => true];
        });

        $needToCreateItems = [];
        foreach ($eventsIds as $eventId) {
            if (! $existedItems->has($eventId)) {
                $needToCreateItems[] = $eventId;
            }
        }

        foreach ($needToCreateItems as $eventId) {
            Element::create('Favorites', [
                'userId' => $userId,
                'schemaId' => 'Events',
                'objectId' => $eventId,
                'isMandatory' => true
            ], $this->user->backend);
        }
    }

    protected function createEventsScores(array $eventsIds, Collection $teams)
    {
        foreach ($eventsIds as $eventId) {
            foreach ($teams as $team) {
                $existed = Element::list('TeamStandingsScores', $this->user->backend, [
                    'take' => -1,
                    'where' => [
                        'eventId' => $eventId,
                        'teamId' => $team->id
                    ]
                ]);

                if ($existed->count() == 0) {
                    foreach (self::DATES as $date) {
                        Element::create('TeamStandingsScores', [
                            'teamId' => $team->id,
                            'eventId' => $eventId,
                            'date' => Carbon::parse($date, 'UTC')->toAtomString(),
                        ], $this->user->backend);
                    }
                }
            }
        }
    }

    public function handle()
    {
        $teams = $this->getTeams();

        $eventsIds = [];
        $teams->each(function ($item) use (&$eventsIds) {
            if (isset($item->fields['eventsIds1']) && is_array($item->fields['eventsIds1'])) {
                foreach ($item->fields['eventsIds1'] as $eventId) {
                    $eventsIds[$eventId] = true;
                }
            }
        });

        $this->markEvents(array_keys($eventsIds));

        $teams->each(function ($team) {
            if (!isset($team->fields['eventsIds1']) or !is_array($team->fields['eventsIds1']) or count($team->fields['eventsIds1']) == 0) {
                return null;
            }

            if (isset($team->fields['userIds1']) && is_array($team->fields['userIds1'])) {
                foreach ($team->fields['userIds1'] as $userId) {
                    $this->createFavoriteItem($team->fields['eventsIds1'], $userId);
                }
            }
        });

        $allEvents = $eventsIds;
        $eventsIds = [];
        $teams->each(function ($item) use (&$eventsIds) {
            if (isset($item->fields['eventsIds2']) && is_array($item->fields['eventsIds2'])) {
                foreach ($item->fields['eventsIds2'] as $eventId) {
                    $eventsIds[$eventId] = true;
                }
            }
        });

        $this->markEvents(array_keys($eventsIds));
        $allEvents = array_merge($allEvents, $eventsIds);

        $this->createEventsScores(array_keys($allEvents), $teams);

        $teams->each(function ($team) {
            if (!isset($team->fields['eventsIds2']) or !is_array($team->fields['eventsIds2']) or count($team->fields['eventsIds2']) == 0) {
                return null;
            }

            if (isset($team->fields['userIds2']) && is_array($team->fields['userIds2'])) {
                foreach ($team->fields['userIds2'] as $userId) {
                    $this->createFavoriteItem($team->fields['eventsIds2'], $userId);
                }
            }
        });
    }
}
