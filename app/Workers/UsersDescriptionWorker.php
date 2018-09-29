<?php

namespace App\Workers;

use App\Workers\BaseWorker;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;

class UsersDescriptionWorker extends BaseWorker
{
    public function handle()
    {
        $userProfiles = Element::list('UserProfiles', $this->user->backend, [
            'take' => -1,
            'where' => [
                '$or' => [
                    [
                        'rewards' => [
                            '$exists' => true,
                        ]
                    ],
                    [
                        'description' => [
                            '$exists' => true
                        ]
                    ]
                ]
            ]
        ], ['en'])->mapWithKeys(function ($item) {
            return [$item->id => [
                    'id' => $item->id,
                    'biography' => $item->fields['biography'] ?? '',
                    'ru' => [
                        'rewards' => $item->fields['rewards'] ?? '',
                        'description' => $item->fields['description'] ?? ''
                    ],
                    'en' => [
                        'rewards' => $item->languages['en']['rewards'] ?? '',
                        'description' => $item->languages['en']['description'] ?? '',
                    ]
                ]
            ];
        });

        $userProfiles->each(function ($item) use (&$filled) {
            $html = view('users/description', [
                'user' => $item['ru'],
                'locale' => 'ru'
            ])->render();

            Element::update('UserProfiles', $item['id'], [
                'biography' => $html
            ], $this->user->backend);

            if ($item['en']['rewards'] || $item['en']['description']) {
                $html = view('users/description', [
                    'user' => $item['en'],
                    'locale' => 'en'
                ])->render();

                Element::updateLanguages('UserProfiles', $item['id'], [
                    'en' => [
                        'biography' => $html
                    ]
                ], $this->user->backend);
            }
        });
    }
}
