<?php

namespace App\Workers;

use App\Workers\BaseWorker;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;

use Illuminate\Support\Collection;

class SectionsSubtitleFixer extends BaseWorker
{
    const LECTURES_SCHEMA = 'Sections';
    const PROFILES_SCHEMA = 'UserProfiles';
    const GENERAL_SECTIONS = [
        '2756d0f5-2976-46ce-9d99-d7939bab960e',
        '3e098ddf-41ef-4e98-95af-3c38da087bf7'
    ];

    private function getLectures()
    {
        $sections = Element::list(self::LECTURES_SCHEMA, $this->user->backend, [
            'where' => [
                'parentId' => [
                    '$in' => self::GENERAL_SECTIONS
                ]
            ],
            'take' => -1
        ])->map(function ($item) {
            return $item->id;
        });

        return Element::list(self::LECTURES_SCHEMA, $this->user->backend, [
            'where' => [
                'parentId' => [
                    '$in' => $sections
                ]
            ],
            'take' => -1
        ]);
    }

    private function getProfiles()
    {
        return Element::list(self::PROFILES_SCHEMA, $this->user->backend, [
            'take' => -1
        ], ['en'])->mapWithKeys(function ($item) {
            return [$item->id => $item];
        });
    }

    private function getStringSpeakers($lecture, $profiles): array
    {
        $result = [
            'ru' => [],
            'en' => []
        ];
        if (isset($lecture->fields['userProfileIds']) && is_array($lecture->fields['userProfileIds'])) {
            foreach ($lecture->fields['userProfileIds'] as $profileId) {
                $profile = $profiles[$profileId] ?? null;

                if (
                    isset($profile->fields['lastName'])
                    && $profile->fields['lastName']
                    && isset($profile->fields['firstName'])
                    && $profile->fields['firstName']
                ) {
                    $result['ru'][] = $profile->fields['lastName'] . ' ' . $profile->fields['firstName'];
                }

                if (
                    isset($profile->languages['en']['lastName'])
                    && $profile->languages['en']['lastName']
                    && isset($profile->languages['en']['firstName'])
                    && $profile->languages['en']['firstName']
                ) {
                    $result['en'][] = $profile->languages['en']['lastName'] . ' ' . $profile->languages['en']['firstName'];
                }
            }
        }
        $result['ru'] = implode(', ', $result['ru']);
        $result['en'] = implode(', ', $result['en']);
        return $result;
    }

    public function handle()
    {
        $lectures = $this->getLectures();
        $profiles = $this->getProfiles();

        foreach ($lectures as $lecture) {
            $speakers = $this->getStringSpeakers($lecture, $profiles);

            Element::update(self::LECTURES_SCHEMA, $lecture->id, [
                'subtitle' => $speakers['ru']
            ], $this->user->backend);

            Element::updateLanguages(self::LECTURES_SCHEMA, $lecture->id, [
                'en' => [
                    'subtitle' => $speakers['en']
                ]
            ], $this->user->backend);
        }
    }
}
