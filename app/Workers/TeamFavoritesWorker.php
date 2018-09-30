<?php

namespace App\Workers;

use App\Workers\BaseWorker;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;

use Illuminate\Support\Collection;

class TeamFavoritesWorker extends BaseWorker
{
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

    public function handle()
    {
        $teams = $this->getTeams();

        $eventsIds = [];
        $teams->each(function ($item) use (&$eventsIds) {
            if (isset($item->fields['eventsIds']) && is_array($item->fields['eventsIds'])) {
                foreach ($item->fields['eventsIds'] as $eventId) {
                    $eventsIds[$eventId] = true;
                }
            }
        });

        $this->markEvents(array_keys($eventsIds));

        $teams->each(function ($team) {
            if (!isset($team->fields['eventsIds']) or !is_array($team->fields['eventsIds']) or count($team->fields['eventsIds']) == 0) {
                return null;
            }

            if (isset($team->fields['userIds']) && is_array($team->fields['userIds'])) {
                foreach ($team->fields['userIds'] as $userId) {
                    $this->createFavoriteItem($team->fields['eventsIds'], $userId);
                }
            }
        });
    }
}
