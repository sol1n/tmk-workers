<?php

namespace App\Console\Commands;

use Appercode\User;
use Appercode\Element;
use Appercode\Backend;

use Illuminate\Console\Command;

use Carbon\Carbon;

class LikesReport extends Command
{
    private $user;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'likes:report';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate likes';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $token = env('TOKEN');

        if (is_null($token)) {
            $this->logger->error('Token not provided');
            exit(1);
        }

        $this->user = User::LoginByToken((new Backend), $token);

        $videos = Element::list('NewsBurgas', $this->user->backend, [
            'take' => -1,
            'where' => [
                'isPublished' => [
                    '$in' => [true, false]
                ]
            ]
        ])->mapWithKeys(function ($item) {
            return [$item->id => $item->fields['title'] ?? ''];
        });

        $likes = Element::list('Ratings', $this->user->backend, [
            'take' => -1,
            'where' => [
                'objectId' => [
                    '$in' => array_keys($videos->toArray())
                ],
                'isDeleted' => false,
                'rating' => 1
            ]
        ])->mapWithKeys(function ($item) use (&$users) {
            if ($item->fields['userId']) {
                $users[$item->fields['userId']] = true;
            }
            return [$item->id => [
                    'objectId' => $item->fields['objectId'],
                    'userId' => $item->fields['userId']
                ]
            ];
        });

        $userProfiles = Element::list('UserProfiles', $this->user->backend, [
            'take' => -1,
            'where' => [
                'userId' => [
                    '$in' => array_keys($users)
                ]
            ]
        ])->mapWithKeys(function ($item) {
            return [$item->fields['userId'] => [
                'firstName' => $item->fields['firstName'],
                'lastName' => $item->fields['lastName'],
                'company' => $item->fields['company'],
            ]];
        })->toArray();

        $results = [];
        foreach ($videos as $id => $video) {
            $results[$id] = [
                'title' => $video,
                'users' => []
            ];
        }

        foreach ($likes as $like) {
            $videoId = $like['objectId'];
            $results[$videoId]['users'][] = isset($userProfiles[$like['userId']])
                ? $userProfiles[$like['userId']]
                : '-';
        }

        foreach ($results as $key => $result) {
            $results[$key]['rating'] = count($result['users']);
        }

        $html = view('likes/report', [
            'results' => collect($results)->sortByDesc('rating')
        ])->render();

        Element::update('TeamStandings', '66f4f39e-88e8-4e8d-b7f3-efc73d67dde7', [
            'html' => $html,
            'time' => Carbon::now()
        ], $this->user->backend);
    }
}
