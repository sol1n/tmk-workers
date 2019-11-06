<?php

namespace App\Workers\MFES;

use App\Workers\BaseWorker;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;
use Appercode\File;
use Appercode\User;

use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

use Carbon\Carbon;

class ProgramImportWorker extends BaseWorker
{
    const BASE_URL = 'https://expoelectroseti.ru';
    const PROGRAM_LIST_URL = 'https://expoelectroseti.ru/app/program.php';
    const PROGRAM_DETAIL_URL = 'https://expoelectroseti.ru/app/program.php?ELEMENT_ID=';
    const EVENTS_COLLECTION = 'Events';

    private $now;

    public function __construct($user, $logger)
    {
        parent::__construct($user, $logger);

        $this->now = (new Carbon())->setTimezone('Europe/Moscow');
    }

    private function uploadFile($url, $parentId, $name): File
    {
        $fileName = $url;
        $fileName = explode('/', $fileName);
        $fileName = $fileName[count($fileName) - 1];

        $fileFields = [
            'parentId' => self::NEWS_FILE_FOLDER,
            'name' => $name,
            'isFile' => true,
            'shareStatus' => 'shared',
            'rights' => [
                'READ' => true
            ]
        ];

        $multipart = [
            [
                'name' => 'file',
                'filename' => $fileName,
                'contents' => file_get_contents(self::BASE_URL . $url),
            ]
        ];

        $file = File::create($fileFields, $this->user->backend);
        $file->upload($multipart);

        return $file;
    }

    private function events()
    {
        $this->log('Events fetching');

        $eventsData = file_get_contents(self::PROGRAM_LIST_URL);
        $eventsData = json_decode($eventsData);

        $areas = Element::list('Areas', $this->user->backend, [
            'take' => -1,
            'include' => ['id', 'externalId', 'externalUpdatedAt', 'createdAt', 'updatedAt', 'ownerId'],
            'where' => [
                'externalId' => [
                    '$exists' => true
                ]
            ]
        ])->mapWithKeys(function(Element $area) {
            return [$area->fields['externalId'] => $area];
        });

        $events = Element::list('Events', $this->user->backend, [
            'take' => -1,
            'include' => ['id', 'externalId', 'externalUpdatedAt', 'createdAt', 'updatedAt', 'ownerId'],
            'where' => [
                'externalId' => [
                    '$exists' => true
                ]
            ]
        ])->mapWithKeys(function(Element $area) {
            return [$area->fields['externalId'] => $area];
        });

        $users = Element::list('UserProfiles', $this->user->backend, [
            'take' => -1,
            'include' => ['id', 'externalId', 'externalUpdatedAt', 'createdAt', 'updatedAt', 'ownerId', 'userId'],
            'where' => [
                'externalId' => [
                    '$exists' => true
                ]
            ]
        ])->mapWithKeys(function(Element $user) {
            return [$user->fields['externalId'] => $user];
        });

        $fetchedSpeakers = [];
        foreach ($eventsData as $day) {
            foreach ($day->HALLS as $area) {
                if (isset($area->EVENTS) && is_array($area->EVENTS)) {
                    foreach ($area->EVENTS as $event) {
                        if (isset($event->SPEAKERS) && is_array($event->SPEAKERS)) {
                            foreach ($event->SPEAKERS as $fetchedSpeaker) {
                                $fetchedSpeakers[$fetchedSpeaker->ID] = $fetchedSpeaker;
                            }
                        }

                        if (isset($event->MODERS) && is_array($event->MODERS)) {
                            foreach ($event->MODERS as $fetchedSpeaker) {
                                $fetchedSpeakers[$fetchedSpeaker->ID] = $fetchedSpeaker;
                            }
                        }
                    }
                }
            }
        }

        foreach ($fetchedSpeakers as $fetchedSpeaker) {
            if (!$users->has($fetchedSpeaker->ID)) {
                $user = 
            }
        }

        dd($fetchedSpeakers);

        foreach ($eventsData as $day) {
            foreach ($day->HALLS as $area) {
                $areaExternalId = str_replace(' ', '', trim($area->NAME));
                if (! $areas->has($areaExternalId)) {
                    $newArea = Element::create('Areas', [
                        'externalId' => $areaExternalId,
                        'title' => trim(htmlspecialchars_decode($area->NAME)),
                        'orderIndex' => $area->SORT,
                    ], $this->user->backend);

                    $areas[$areaExternalId] = $newArea;

                    $this->log('Created area ' . trim($area->NAME) . ' (https://web.appercode.com/electroseti/Areas/' . $newArea->id . '/edit)');
                }

                if (isset($area->EVENTS) && is_array($area->EVENTS)) {
                    foreach ($area->EVENTS as $event) {
                        if (! $events->has($event->ID)) {
                            $extendedEventInfo = file_get_contents(self::PROGRAM_DETAIL_URL . $event->ID);
                            $extendedEventInfo = json_decode($extendedEventInfo);

                            $newEvent = Element::create('Events', [
                                'externalId' => $event->ID,
                                'externalUpdatedAt' => $event->UPDATE_TIME,
                                'title' => trim(htmlspecialchars_decode($event->NAME)),
                                'areaId' => $areas[$areaExternalId]->id,
                                'beginAt' => Carbon::parse($event->DATE_ACTIVE_FROM, 'UTC')->setTimezone('Europe/Moscow')->toAtomString(),
                                'endAt' => Carbon::parse($event->DATE_ACTIVE_TO, 'UTC')->setTimezone('Europe/Moscow')->toAtomString(),
                                'description' => $extendedEventInfo->DETAIL_TEXT ?? ''
                            ], $this->user->backend);

                            $this->log('Created event ' . trim(htmlspecialchars_decode($event->NAME)) . ' (https://web.appercode.com/electroseti/Events/' . $newEvent->id . '/edit)');
                        }elseif (isset($event->UPDATE_TIME) && $events[$event->ID]->fields['externalUpdatedAt'] != $event->UPDATE_TIME) {
                            // need to update
                        } else {
                            // need to skip
                        }
                    }
                }
            }
        }

        $this->log('Events imported successfully');
    }

    public function handle()
    {
        $this->events();
    }
}
