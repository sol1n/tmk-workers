<?php

namespace App\Workers\MFES;

use App\Workers\BaseWorker;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;
use Appercode\File;
use Appercode\EventMemberships;

use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

use Carbon\Carbon;

class ProgramImportWorker extends BaseWorker
{
    const BASE_URL = 'https://expoelectroseti.ru';
    const PROGRAM_LIST_URL = 'https://expoelectroseti.ru/app/program.php';
    const PROGRAM_DETAIL_URL = 'https://expoelectroseti.ru/app/program.php?ELEMENT_ID=';
    const EVENTS_COLLECTION = 'Events';
    const SPEAKERS_PHOTOS_DIR = '17324f61-9192-4f7f-90aa-b84c4b185952';
    const SPEAKERS_GROUP = '1c605559-8196-4c64-b99a-4e4de0505e83';
    const SPEAKERS_TAG = '5d3188db-6cbd-466a-8779-6d8cef4f56b1';

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
            'parentId' => $parentId,
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
                'contents' => file_get_contents($url),
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

        $tags = Element::list('TagsEvents', $this->user->backend, [
            'take' => -1,
            'include' => ['id', 'title', 'createdAt', 'updatedAt', 'ownerId', 'userId']
        ])->mapWithKeys(function(Element $tag) {
            return [$tag->fields['title'] => $tag];
        });

        $fetchedTags = [];
        foreach ($eventsData as $day) {
            foreach ($day->HALLS as $area) {
                if (isset($area->EVENTS) && is_array($area->EVENTS)) {
                    foreach ($area->EVENTS as $event) {
                        if (isset($event->TYPE) && $event->TYPE) {
                            $fetchedTags[trim(htmlspecialchars_decode($event->TYPE))] = 1;
                        }
                        if (isset($event->VID) && $event->VID) {
                            $fetchedTags[trim(htmlspecialchars_decode($event->VID))] = 1;
                        }
                    }
                }
            }
        }

        foreach ($fetchedTags as $title => $k) {
            if (! $tags->has($title)) {
                $newTag = Element::create('TagsEvents', [
                    'title' => $title
                ], $this->user->backend);

                $tags[$title] = $newTag;
                $this->log('Created tag ' . trim($area->NAME) . ' (https://web.appercode.com/electroseti/TagsEvents/' . $newTag->id . '/edit)');
            }
        }

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
                $username = $fetchedSpeaker->ID . rand(100,999);
                $user = User::create($this->user->backend, [
                    'username' => $username,
                    'password' => $username,
                    'roleId' => 'Participant',
                    'isPasswordExpired' => true
                ]);

                $fio = trim($fetchedSpeaker->NAME);
                $fio = explode(' ', $fio);

                $extension = explode('.', $fetchedSpeaker->DETAIL_PIC);
                $extension = $extension[count($extension) - 1];

                if (isset($fetchedSpeaker->DETAIL_PIC) && $fetchedSpeaker->DETAIL_PIC) {
                    $photo = $this->uploadFile(self::BASE_URL . $fetchedSpeaker->DETAIL_PIC, self::SPEAKERS_PHOTOS_DIR, trim($fetchedSpeaker->NAME) . $extension);
                } else {
                    $photo = null;
                }

                $profile = Element::create('UserProfiles', [
                    'userId' => $user->id,
                    'firstName' => $fio[1] ?? '',
                    'lastName' => $fio[0] ?? '',
                    'middleName' => $fio[2] ?? '',
                    'code' => $username,
                    'photoFileId' => $photo->id,
                    'groupIds' => [self::SPEAKERS_GROUP],
                    'position' => trim(htmlspecialchars_decode($fetchedSpeaker->POSITION)),
                    'company' => trim(htmlspecialchars_decode($fetchedSpeaker->COMPANY)),
                    'tagsIds' => [self::SPEAKERS_TAG],
                    'externalId' => $fetchedSpeaker->ID,
                    'externalUpdatedAt' => $fetchedSpeaker->UPDATE_TIME,
                ], $this->user->backend);

                $enFio = trim($fetchedSpeaker->NAME_ENG);
                $enFio = explode(' ', $enFio);

                Element::updateLanguages('UserProfiles', $profile->id, [
                    'en' => [
                        'firstName' => $enFio[1] ?? '',
                        'lastName' => $enFio[0] ?? '',
                        'middleName' => $enFio[2] ?? '',
                        'position' => trim(htmlspecialchars_decode($fetchedSpeaker->POSITION_ENG)),
                        'company' => trim(htmlspecialchars_decode($fetchedSpeaker->COMPANY_ENG))
                    ]
                ], $this->user->backend);

                $users[$fetchedSpeaker->ID] = $profile;

                $this->log('Created user ' . trim($fetchedSpeaker->NAME) . ' (https://web.appercode.com/electroseti/users/' . $user->id . '/edit)');
            } elseif ($users[$fetchedSpeaker->ID]->fields['externalUpdatedAt'] != $fetchedSpeaker->UPDATE_TIME) {

                $fio = trim($fetchedSpeaker->NAME);
                $fio = explode(' ', $fio);

                $extension = explode('.', $fetchedSpeaker->DETAIL_PIC);
                $extension = $extension[count($extension) - 1];

                if (isset($fetchedSpeaker->DETAIL_PIC) && $fetchedSpeaker->DETAIL_PIC) {
                    $photo = $this->uploadFile(self::BASE_URL . $fetchedSpeaker->DETAIL_PIC, self::SPEAKERS_PHOTOS_DIR, trim($fetchedSpeaker->NAME) . $extension);
                } else {
                    $photo = null;
                }

                Element::update('UserProfiles', $users[$fetchedSpeaker->ID]->id, [
                    'firstName' => $fio[1] ?? '',
                    'lastName' => $fio[0] ?? '',
                    'middleName' => $fio[2] ?? '',
                    'photoFileId' => $photo->id,
                    'groupIds' => [self::SPEAKERS_GROUP],
                    'position' => trim(htmlspecialchars_decode($fetchedSpeaker->POSITION)),
                    'company' => trim(htmlspecialchars_decode($fetchedSpeaker->COMPANY)),
                    'tagsIds' => [self::SPEAKERS_TAG],
                    'externalId' => $fetchedSpeaker->ID,
                    'externalUpdatedAt' => $fetchedSpeaker->UPDATE_TIME,
                ], $this->user->backend);

                $enFio = trim($fetchedSpeaker->NAME_ENG);
                $enFio = explode(' ', $enFio);

                Element::updateLanguages('UserProfiles', $users[$fetchedSpeaker->ID]->id, [
                    'en' => [
                        'firstName' => $enFio[1] ?? '',
                        'lastName' => $enFio[0] ?? '',
                        'middleName' => $enFio[2] ?? '',
                        'position' => trim(htmlspecialchars_decode($fetchedSpeaker->POSITION_ENG)),
                        'company' => trim(htmlspecialchars_decode($fetchedSpeaker->COMPANY_ENG))
                    ]
                ], $this->user->backend);

                $this->log('Updated user ' . trim($fetchedSpeaker->NAME) . ' (https://web.appercode.com/electroseti/users/' . $users[$fetchedSpeaker->ID]->fields['userId'] . '/edit)');
            } else {
                $this->log('Passed user ' . trim($fetchedSpeaker->NAME) . ' (https://web.appercode.com/electroseti/users/' . $users[$fetchedSpeaker->ID]->fields['userId'] . '/edit)');
            }
        }

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
                        $eventSpeakers = [];

                        if (isset($event->SPEAKERS) && is_array($event->SPEAKERS)) {
                            foreach ($event->SPEAKERS as $fetchedSpeaker) {
                                $eventSpeakers[$fetchedSpeaker->ID] = $fetchedSpeaker;
                            }
                        }

                        if (isset($event->MODERS) && is_array($event->MODERS)) {
                            foreach ($event->MODERS as $fetchedSpeaker) {
                                $eventSpeakers[$fetchedSpeaker->ID] = $fetchedSpeaker;
                            }
                        }

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

                            foreach ($eventSpeakers as $eventSpeaker) {
                                $userId = $users[$eventSpeaker->ID]->fields['userId'];

                                EventMemberships::create($this->user->backend, [
                                    'eventSchemaId' => 'Events',
                                    'eventObjectId' => $newEvent->id,
                                    'userId' => $userId,
                                    'status' => 'confirmed',
                                    'type' => 'speaker'
                                ]);
                            }

                            $this->log('Created event ' . trim(htmlspecialchars_decode($event->NAME)) . ' (https://web.appercode.com/electroseti/Events/' . $newEvent->id . '/edit)');
                        } elseif (isset($event->UPDATE_TIME) && $events[$event->ID]->fields['externalUpdatedAt'] != $event->UPDATE_TIME) {
                            $extendedEventInfo = file_get_contents(self::PROGRAM_DETAIL_URL . $event->ID);
                            $extendedEventInfo = json_decode($extendedEventInfo);

                            $tagIds = [];
                            if (isset($event->TYPE) && $event->TYPE) {
                                $tagIds[] = $tags[trim(htmlspecialchars_decode($event->TYPE))]->id;
                            }
                            if (isset($event->VID) && $event->VID) {
                                $tagIds[] = $tags[trim(htmlspecialchars_decode($event->VID))]->id;
                            }

                            Element::update('Events', $events[$event->ID]->id, [
                                'externalId' => $event->ID,
                                'externalUpdatedAt' => $event->UPDATE_TIME,
                                'title' => trim(htmlspecialchars_decode($event->NAME)),
                                'areaId' => $areas[$areaExternalId]->id,
                                'beginAt' => Carbon::parse($event->DATE_ACTIVE_FROM, 'UTC')->setTimezone('Europe/Moscow')->toAtomString(),
                                'endAt' => Carbon::parse($event->DATE_ACTIVE_TO, 'UTC')->setTimezone('Europe/Moscow')->toAtomString(),
                                'description' => $extendedEventInfo->DETAIL_TEXT ?? '',
                                'tagsIds' => $tagIds
                            ], $this->user->backend);

                            // foreach ($eventSpeakers as $eventSpeaker) {
                            //     $userId = $users[$eventSpeaker->ID]->fields['userId'];

                            //     EventMemberships::create($this->user->backend, [
                            //         'eventSchemaId' => 'Events',
                            //         'eventObjectId' => $events[$event->ID]->id,
                            //         'userId' => $userId,
                            //         'status' => 'confirmed',
                            //         'type' => 'speaker'
                            //     ]);
                            // }

                            $this->log('Updated event ' . trim(htmlspecialchars_decode($event->NAME)) . ' (https://web.appercode.com/electroseti/Events/' .$events[$event->ID]->id . '/edit)');
                        } else {
                            $this->log('Passed event ' . trim(htmlspecialchars_decode($event->NAME)) . ' (https://web.appercode.com/electroseti/Events/' .$events[$event->ID]->id . '/edit)');
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
