<?php

namespace App\Console\Commands;

use Appercode\User;
use Appercode\Backend;

use App\Workers\MFES\NewsImportWorker;
use App\Workers\MFES\ProgramImportWorker;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MFESImport extends Command
{
    private $worker;

    private $logger;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mfes:import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Event data sync with site';

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
        $token = env('MFES_TOKEN');

        $this->logger = Log::channel('regular');
        $this->logger->info('Started');

        if (is_null($token)) {
            $this->logger->error('Token not provided');
            exit(1);
        }

        $user = User::LoginByToken((new Backend('electroseti')), $token);

        $newsWorker = new NewsImportWorker($user, $this->logger);
        //$newsWorker->handle();

        $programWorker = new ProgramImportWorker($user, $this->logger);
        $programWorker->handle();
    }
}
