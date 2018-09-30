<?php

namespace App\Workers;

use App\Workers\BaseWorker;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;

class EventsChecker extends BaseWorker
{
    public function handle()
    {
        $favoriteItems = Element::list('Favorites', $this->user->backend, [
            'where' => [
                'schemaId' => 'Events',
                'isMandatory' => true,
                'objectId' => [
                    '$exists' => true
                ],
                'isPublished' => [
                    '$in' => [true, false]
                ]
            ],
            'take' => -1
        ])->each(function ($item) use (&$eventsList) {
            $eventsList[$item->fields['objectId']] = true;
        });

        $events = Element::list('Events', $this->user->backend, [
            'where' => [
                'id' => [
                    '$in' => array_keys($eventsList)
                ]
            ],
            'take' => -1
        ])->map(function ($item) {
            return $item->fields['title'];
        });

        dd($events);
    }
}
