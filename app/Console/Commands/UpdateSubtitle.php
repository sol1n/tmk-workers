<?php

namespace App\Console\Commands;

use Appercode\User;
use Appercode\Backend;

use App\Workers\SectionsSubtitleFixer;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateSubtitle extends Command
{
    private $worker;
    private $logger;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lectures:subtitle';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Updates lectures subtitle';

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
        $this->logger = Log::channel('regular');
        $this->logger->info('Started');

        $token = env('TOKEN');

        if (is_null($token)) {
            $this->logger->error('Token not provided');
            exit(1);
        }

        $user = User::LoginByToken((new Backend), $token);
        $this->worker = new SectionsSubtitleFixer($user, $this->logger);
        $this->worker->handle();

        $this->logger->info('All done');
    }
}
