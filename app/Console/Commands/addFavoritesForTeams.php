<?php

namespace App\Console\Commands;

use Appercode\User;
use Appercode\Backend;

use App\Workers\TeamFavoritesWorker;

use Illuminate\Console\Command;

class addFavoritesForTeams extends Command
{
    private $worker;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'teams:favorites';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates mandatory favorites elemets for team events';

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
        $this->worker = new TeamFavoritesWorker($user);
        $this->worker->handle();
    }
}
