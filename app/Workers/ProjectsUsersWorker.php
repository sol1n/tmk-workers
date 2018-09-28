<?php

namespace App\Workers;

use App\Workers\BaseWorker;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;

use Illuminate\Support\Collection;

class ProjectsUsersWorker extends BaseWorker
{
    protected $structure;

    public function handle()
    {
        $allProfiles = Element::list('UserProfiles', $this->user->backend, [
            'take' => -1,
            'firstName' => [
                '$exists' => true
            ],
            'lastName' => [
                '$exists' => true
            ]
        ])->mapWithKeys(function($item) {
            return [($item->fields['lastName'] . ' ' . $item->fields['firstName']) => $item->id];
        });

        $allProjects = Element::list('ProjectBase', $this->user->backend, [
            'take' => -1
        ])->mapWithKeys(function($item) {
            return [trim($item->fields['title']) => $item->id];
        });

        $projects = [];
        $foundedAuthors = 0;
        $foundedCurators = 0;
        $processedProjects = 0;
        $file = fopen(storage_path('app/base.csv'), 'r');
        while (($line = fgetcsv($file, 99999, ';')) !== false) {
            $author = trim($line[1]);
            $curator = trim($line[2]);
            $project = trim($line[0]);

            $t = explode(' ', $author);
            if (is_array($t) && count($t) > 1) {
                $author = $t[0] . ' ' . $t[1];
            }

            $t = explode(' ', $curator);
            if (is_array($t) && count($t) > 1) {
                $curator = $t[0] . ' ' . $t[1];
            }

            $curators = [];
            $authors = [];

            if (isset($allProfiles[$curator]) && $allProfiles[$curator]) {
                $curators[] = $allProfiles[$curator];
                $authors[] = $allProfiles[$curator];
            }

            if (isset($allProfiles[$author]) && $allProfiles[$author]) {
                $authors[] = $allProfiles[$author];
            }

            if ($curators && $authors) {
                if ($allProjects->get($project)) {
                    Element::update('ProjectBase', $allProjects->get($project), [
                        'userProfileIds' => $authors,
                        'curatorProfileIds' => $curators
                    ], $this->user->backend);
                }
            }
        }
        
        fclose($file);

        dd($processedProjects);
    }
}
