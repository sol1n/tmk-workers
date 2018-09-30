<?php

namespace App\Console\Commands;

use Appercode\User;
use Appercode\Backend;

use App\Workers\RatingsFiller;

use Illuminate\Console\Command;

class TeamRatings extends Command
{
    private $worker;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'teams:rating';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generates teams rating';

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
        $this->worker = new RatingsFiller($user);
        $this->worker->handle();
    }
}
