<?php

namespace App\Workers;

use App\Workers\BaseWorker;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;

use Carbon\Carbon;

use Illuminate\Support\Collection;

class RatingsFiller extends BaseWorker
{
    protected function getPages(): Collection
    {
        return Element::list('TeamStandings', $this->user->backend, [
            'take' => -1,
            'where' => [
                'date' => [
                    '$exists' => true
                ],
                'isPublished' => [
                    '$in' => [true, false]
                ]
            ]
        ]);
    }

    protected function getScores(Carbon $date)
    {
        $scores = Element::list('TeamStandingsScores', $this->user->backend, [
            'take' => -1,
            'where' => [
                'date' => [
                    '$lt' => $date->endOfDay()->toAtomString()
                ]
            ],
            'order' => [
                'date' => 'asc'
            ]
        ]);

        $teams = [];
        foreach ($scores as $score) {
            if (isset($teams[$score->fields['teamId']])) {
                continue;
            }

            $teams[$score->fields['teamId']] = [
                'total' => 0,
                'dates' => []
            ];
        }

        foreach ($scores as $score) {
            $teamId = $score->fields['teamId'];
            $date = Carbon::parse($score->fields['date'], 'UTC')->format('d.m.Y');
            if (isset($teams[$teamId][$date])) {
                continue;
            }

            $title = implode(' ', [
                Carbon::parse($score->fields['date'], 'UTC')->format('j'),
                mb_strtolower(__('months.full.' . Carbon::parse($score->fields['date'], 'UTC')->month))
            ]);

            $teams[$teamId]['dates'][$date] = [
                'dayTotal' => 0,
                'title' => $title,
                'scores' => []
            ];
        }

        foreach ($scores as $score) {
            $teamId = $score->fields['teamId'];
            $date = Carbon::parse($score->fields['date'], 'UTC')->format('d.m.Y');

            $teams[$teamId]['total'] += $score->fields['score'];
            $teams[$teamId]['dates'][$date]['scores'][] = $score;
            $teams[$teamId]['dates'][$date]['dayTotal'] += $score->fields['score'];
        }

        return $teams;
    }

    public function handle()
    {
        $pages = $this->getPages();
        $events = Element::list('Events', $this->user->backend, [
            'take' => -1
        ])->mapWithKeys(function($item) {
            return [$item->id => $item->fields['title']];
        });

        $teams = Element::list('TeamStandingsTeams', $this->user->backend, [
            'take' => -1
        ])->mapWithKeys(function($item) {
            return [$item->id => $item->fields['title']];
        });

        foreach ($pages as $page) {
            $date = Carbon::parse($page->fields['date'], 'UTC');

            $scores = $this->getScores($date);

            $html = view('ratings/description', [
                'scores' => $scores,
                'events' => $events,
                'teams' => $teams,
                'currentDate' => $date->format('d.m.Y')
            ])->render();

            Element::update('TeamStandings', $page->id, [
                'html' => $html
            ], $this->user->backend);


        }
    }
}
