<?php

namespace App\Console\Commands;

use Appercode\User;
use Appercode\Backend;

use App\Workers\TeamScoresCreator;

use Illuminate\Console\Command;

class TeamScores extends Command
{
    private $worker;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'teams:scores';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generates teams scrores';

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

        $user = User::LoginByToken((new Backend), $token);
        $this->worker = new TeamScoresCreator($user);
        $this->worker->handle();
    }
}
