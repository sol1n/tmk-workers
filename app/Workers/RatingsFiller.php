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

    protected function getScores(Carbon $currentDate, $titles = [], $locale = 'ru')
    {
        $scores = Element::list('TeamStandingsScores', $this->user->backend, [
            'take' => -1,
            'where' => [
                'date' => [
                    '$lt' => $currentDate->endOfDay()->toAtomString()
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
                'title' => $titles[$score->fields['teamId']] ?? '',
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
                mb_strtolower(__('months.full.' . Carbon::parse($score->fields['date'], 'UTC')->month, [], $locale))
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


        $maxIndex = [];
        $maxScore = -10000;

        foreach ($teams as $teamId => $team) {
            if ($team['total'] >= $maxScore) {
                $maxScore = $team['total'];
                if (isset($maxIndex[$team['total']])) {
                    $maxIndex[$team['total']][] = $teamId;
                } else {
                    $maxIndex[$team['total']] = [$teamId];
                }
            }
        }

        if ($maxScore > -10000) {
            foreach ($maxIndex[$maxScore] as $teamId) {
                $teams[$teamId]['winner'] = true;
            }
        }

        $sort = function ($a, $b) {
            if ($a['total'] > $b['total']) {
                return 1;
            } elseif ($a['total'] == $b['total']) {
                return strcasecmp($a['title'], $b['title']);
            } else {
                return -1;
            }
        };

        uasort($teams, $sort);

        return $teams;
    }

    public function handle()
    {
        $pages = $this->getPages();
        $events = Element::list('Events', $this->user->backend, [
            'take' => -1,
            'where' => [
                'isPublished' => [
                    '$in' => [true, false]
                ]
            ]
        ], ['en']);

        $ruEvents = $events->mapWithKeys(function ($item) {
            return [$item->id => $item->fields['title']];
        });

        $enEvents = $events->mapWithKeys(function ($item) {
            return [
                $item->id => isset($item->languages['en']['title'])
                    ? $item->languages['en']['title']
                    : $item->fields['title']
            ];
        });

        $teams = Element::list('TeamStandingsTeams', $this->user->backend, [
            'take' => -1
        ], ['en']);

        $ruTeams = $teams->mapWithKeys(function ($item) {
            return [$item->id => $item->fields['title']];
        });

        $enTeams = $teams->mapWithKeys(function ($item) {
            return [
                $item->id => isset($item->languages['en']['title'])
                    ? $item->languages['en']['title']
                    : $item->fields['title']
            ];
        });

        foreach ($pages as $page) {
            $date = Carbon::parse($page->fields['date'], 'UTC');

            $html = view('ratings/description', [
                'scores' => $this->getScores($date, $ruTeams->toArray(), 'ru'),
                'events' => $ruEvents,
                'teams' => $ruTeams,
                'currentDate' => $date->format('d.m.Y'),
                'locale' => 'ru'
            ])->render();

            Element::update('TeamStandings', $page->id, [
                'html' => $html
            ], $this->user->backend);

            $enHtml = view('ratings/description', [
                'scores' => $this->getScores($date, $enTeams->toArray(), 'en'),
                'events' => $enEvents,
                'teams' => $enTeams,
                'currentDate' => $date->format('d.m.Y'),
                'locale' => 'en'
            ])->render();

            Element::updateLanguages('TeamStandings', $page->id, [
                'en' => [
                    'html' => $enHtml
                ]
            ], $this->user->backend);
        }
    }
}
