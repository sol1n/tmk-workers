<?php

namespace App\Console\Commands;

use Appercode\User;
use Appercode\Backend;

use App\Workers\Magnit\PointsWorker;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MagnitPoints extends Command
{
    private $worker;

    private $logger;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'magnit:points';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Accrues points';

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
        $token = env('MAGNIT_TOKEN');

        $this->logger = Log::channel('regular');
        $this->logger->info('Started');

        if (is_null($token)) {
            $this->logger->error('Token not provided');
            exit(1);
        }

        $user = User::LoginByToken((new Backend('magnitmo')), $token);

        $pointsWorker = new PointsWorker($user, $this->logger);
        $pointsWorker->handle();

        $this->logger->info('All done');
    }
}
