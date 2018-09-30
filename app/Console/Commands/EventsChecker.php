<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use Appercode\User;
use Appercode\Backend;

use App\Workers\EventsChecker as EventsCheckerWorker;

class EventsChecker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fills up events CheckIn field';

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
        $this->info('Started');
        $token = env('TOKEN');

        if (is_null($token)) {
            $this->logger->error('Token not provided');
            exit(1);
        }

        $user = User::LoginByToken((new Backend), $token);
        $this->worker = new EventsCheckerWorker($user);
        $this->worker->handle();

        $this->info('All done');
    }
}
