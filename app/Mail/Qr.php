<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class Qr extends Mailable
{
    use Queueable, SerializesModels;

    private $file;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($file)
    {
        $this->file = $file;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->from(env('MAIL_USERNAME'))
            ->view('mail.qr')
            ->attach($this->file, [
                'as' => 'List.pdf',
                'mime' => 'application/pdf',
            ]);
        ;
    }
}
