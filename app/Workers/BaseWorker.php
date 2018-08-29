<?php

namespace App\Workers;

use Appercode\User;
use Appercode\Backend;

class BaseWorker
{
    protected $user;
    protected $logger;

    public function __construct(User $user, $logger = null)
    {
        $this->user = $user;
        $this->logger = $logger;
    }

    protected function log(string $message, $level = 'info')
    {
        if (!is_null($this->logger)) {
            if ($level == 'error') {
                $this->logger->error($message);
            } else {
                $this->logger->info($message);
            }
        }
    }
}
