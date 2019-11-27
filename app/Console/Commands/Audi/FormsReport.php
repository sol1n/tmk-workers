<?php

namespace App\Console\Commands\Audi;

use App\Form2;
use App\FormResponse;
use App\Helpers\AdminTokens;
use App\Helpers\CommandsHelper;
use App\Services\ObjectManager;
use App\Services\SchemaManager;
use App\Settings;
use Illuminate\Console\Command;
use phpDocumentor\Reflection\Types\Self_;

class FormsReport extends Command
{
    const BACKEND_CODE = 'audi';
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audi:forms';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * @var SchemaManager
     */
    private $schemaManager;
    /**
     * @var ObjectManager
     */
    private $objectManager;

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
        $adminToken = new AdminTokens();
        $backend = $adminToken->getSession(self::BACKEND_CODE, '', true);

        CommandsHelper::setBackend($backend);
        $this->schemaManager = new SchemaManager($backend);
        $this->objectManager = new ObjectManager($backend);
        $formId = 'bafe639d-de25-450b-be34-f210350caf8b';


        $htmlPagesSchema = $this->schemaManager->find('HtmlRating');

        $form = Form2::get($formId, $backend);

        $responses = FormResponse::list($backend, [
            'take' => -1,
            'skip' => 0,
            'where' => [
                'formId' => $formId,
                'submittedAt' => ['$exists' => true]
            ]
        ]);

        $responses = FormResponse::processAnswers($responses, $form);

        $userIds = $responses->pluck('userId')->unique()->map(function ($item) {
            return (int)$item;
        })->values();

        $users = [];

        if ($userIds) {
            $userProfileSchema = app(Settings::class)->getProfileSchema();

            $users = $this->objectManager->allByPage($userProfileSchema, [
                'take' => -1,
                'search' => [
                    'userId' => ['$in' => $userIds]
                ],
                'include' => ['id', 'imageFileId', 'userId', ['groupIds' => ['id', 'title']], 'lastName', 'firstName', 'position', 'company']
            ])->keyBy('fields.userId');
        }

        $groupedRating = [];
        foreach ($responses as $response) {
            if (isset($users[$response->userId])) {
                $correctAnswers = $response->correctCount;
                $user = $users[$response->userId];
                foreach ($user->fields['groupIds'] as $group) {
                    if (strpos($group['title'], '.10') !== false or strpos($group['title'], '.11') !== false) {
                        if (!isset($groupedRating[$group['id']])) {
                            $groupedRating[$group['id']] = [
                                'name' => $group['title'],
                                'users' => []
                            ];
                        }
                        if ((isset($groupedRating[$group['id']]['users'][$response->userId]) && $correctAnswers > $groupedRating[$group['id']]['users'][$response->userId]['amount'])
                            or !(isset($groupedRating[$group['id']]['users'][$response->userId]))) {
                            $groupedRating[$group['id']]['users'][$response->userId] = [
                                'profile' => $user,
                                'amount' => $correctAnswers
                            ];
                        }
                    }
                }
            }
        }

        $creates = [];
        foreach ($groupedRating as &$group) {
            $amounts = $lastNames = $firstNames = [];
            foreach ($group['users'] as $row) {
                $amounts[] = $row['amount'];
                $lastNames[] = $row['profile']->fields['lastName'];
                $firstNames[] = $row['profile']->fields['firstName'];
            }
            array_multisort($amounts, SORT_DESC, $lastNames, SORT_ASC, $firstNames, SORT_ASC, $group['users']);

            $view = view('v2.audi.html-report', [
                'users' => $group['users']
            ])->render();

            $pageData = [
                'html' => $view,
                'title' => $group['name']
            ];

            $htmlPage = $this->objectManager->allByPage($htmlPagesSchema, [
                'take' => 1,
                'skip' => 0,
                'search' => ['title' => $group['name']],
                'include' => 'id'
            ])->first();

            if ($htmlPage) {
                $this->objectManager->update($htmlPagesSchema, [$htmlPage->id], $pageData);
            } else {
                $creates[] = $pageData;
            }
        }

        if (count($creates)) {
            $this->objectManager->bulkCreate($htmlPagesSchema, $creates, false);
        }
        $this->info('done');
    }
}
