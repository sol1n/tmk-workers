<?php

namespace App\Console\Commands;

use Appercode\User;
use Appercode\Backend;

use App\Workers\UsersDescriptionWorker;

use Illuminate\Console\Command;

class UserDescription extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:description';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fills up userProfile field from biography and rewards';

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
        $this->worker = new UsersDescriptionWorker($user);
        $this->worker->handle();

        $this->info('All done');
    }
}
