<?php

namespace App\Workers;

use App\Workers\BaseWorker;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;

use Illuminate\Support\Collection;

class ProjectsWorker extends BaseWorker
{
    const PROJECTS_COLLECTION = 'ProjectBase';

    protected $structure;

    protected function loadYears(): Collection
    {
        return Element::list(self::PROJECTS_COLLECTION, $this->user->backend, [
            'take' => -1,
            'where' => [
                'parentId' => [
                    '$exists' => false
                ],
            ]
        ])->mapWithKeys(function ($item) {
            return [$item->id => $item];
        });
    }

    protected function loadSections(array $yearsIds): Collection
    {
        if (empty($yearsIds)) {
            return collect([]);
        }

        return Element::list(self::PROJECTS_COLLECTION, $this->user->backend, [
            'take' => -1,
            'where' => [
                'parentId' => [
                    '$in' => $yearsIds
                ],
            ]
        ])->mapWithKeys(function ($item) {
            return [$item->id => $item];
        });
    }

    protected function loadProjects(array $sectionsIds): Collection
    {
        if (empty($sectionsIds)) {
            return collect([]);
        }

        return Element::list(self::PROJECTS_COLLECTION, $this->user->backend, [
            'take' => -1,
            'where' => [
                'parentId' => [
                    '$in' => $sectionsIds
                ]
            ]
        ])->mapWithKeys(function ($item) {
            return [$item->id => $item];
        });
    }

    protected function loadProfiles(Collection $projects)
    {
        $userProfiles = [];
        $projects->each(function ($item) use (&$userProfiles) {
            if (isset($item->fields['userProfileIds']) && count($item->fields['userProfileIds'])) {
                foreach ($item->fields['userProfileIds'] as $profileId) {
                    $userProfiles[$profileId] = true;
                }
            }

            if (isset($item->fields['curatorProfileIds']) && count($item->fields['curatorProfileIds'])) {
                foreach ($item->fields['curatorProfileIds'] as $profileId) {
                    $userProfiles[$profileId] = true;
                }
            }
        });

        $userProfiles = array_keys($userProfiles);

        if (empty($userProfiles)) {
            return collect([]);
        }

        return Element::list('UserProfiles', $this->user->backend, [
            'take' => -1,
            'where' => [
                'id' => [
                    '$in' => $userProfiles
                ]
            ]
        ])->mapWithKeys(function ($item) {
            return [$item->id => $item];
        });
    }

    protected function loadStructure()
    {
        $this->structure['years'] = $this->loadYears();
        $this->structure['sections'] = $this->loadSections(
            $this->structure['years']->map(function ($item) {
                return $item->id;
            })->values()->toArray()
        );
        $this->structure['projects'] = $this->loadProjects(
            $this->structure['sections']->map(function ($item) {
                return $item->id;
            })->values()->toArray()
        );
        $this->structure['profiles'] = $this->loadProfiles($this->structure['projects']);
    }

    protected function updateProject(Element $project)
    {
        $section = $this->structure['sections'][$project->fields['parentId']];
        $project->section = $section->fields['title'] ?? '';
        $project->curators = $project->fields['textCurator'] ?? '';
        $project->authors = $project->fields['textAuthor'] ?? '';
        $project->company = $project->fields['textCompany'] ?? '';

        $html = view('projects/description', ['project' => $project])->render();

        Element::update(self::PROJECTS_COLLECTION, $project->id, [
            'description' => $html,
            'subtitle' => is_null($project->fields['projectResult']) ? '' : trans('project.status-' . $project->fields['projectResult'])
        ], $this->user->backend);
    }

    public function handle()
    {
        $this->loadStructure();

        foreach ($this->structure['projects'] as $project) {
            $this->updateProject($project);
        }
    }
}
