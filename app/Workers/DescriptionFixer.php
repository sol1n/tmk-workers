<?php

namespace App\Workers;

use App\Workers\BaseWorker;
use App\Helpers\HtmlSanitizer;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;

use Illuminate\Support\Collection;

class DescriptionFixer extends BaseWorker
{
    const LECTURES_SCHEMA = 'Sections';
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
        ], ['en']);
    }

    private function processDescription(string $description, string $title)
    {
        $description = HtmlSanitizer::clear($description);

        if (mb_strpos($description, '<h3>') === false) {
            $description = view('lectures/description', [
                'title' => $title,
                'description' => $description
            ])->render();
        }

        return $description;
    }

    public function handle()
    {
        $lectures = $this->getLectures();

        foreach ($lectures as $lecture) {
            if (isset($lecture->fields['description']) && isset($lecture->fields['title'])) {
                $newDescription = $this->processDescription($lecture->fields['description'], $lecture->fields['title']);

                Element::update(self::LECTURES_SCHEMA, $lecture->id, [
                    'description' => $newDescription
                ], $this->user->backend);
            }
            
            if (isset($lecture->languages['en']['description']) && isset($lecture->languages['en']['title'])) {
                $newDescription = $this->processDescription($lecture->languages['en']['description'], $lecture->languages['en']['title']);

                Element::updateLanguages(self::LECTURES_SCHEMA, $lecture->id, [
                    'en' => [
                        'description' => $newDescription
                    ]
                ], $this->user->backend);
            }
        }
    }
}
