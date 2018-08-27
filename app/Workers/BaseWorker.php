<?php

namespace App\Workers;

use Appercode\User;
use Appercode\Backend;

class BaseWorker
{
    protected $user;
    protected $logger;

    public function __construct(User $user, $logger)
    {
        $this->user = $user;
        $this->logger = $logger;
    }
}
