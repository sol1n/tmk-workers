<?php

namespace App\Console\Commands;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

use LaravelQRCode\Facades\QRCode;
use Barryvdh\DomPDF\Facade as DomPDF;

use Illuminate\Support\Facades\Mail;
use App\Mail\Qr as QrMail;

use Carbon\Carbon;

class GenerateQR extends Command
{
    private $logger;

    private $collectionName;
    private $email;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate:qr {collection} {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generates QR codes for collection items';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    protected function qrText($schema, $element): string
    {
        return "appercode-qr-events:$schema:$element";
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
            $this->error('Token not provided');
            exit(1);
        }

        $this->collectionName = $this->argument('collection');
        $this->email = $this->argument('email');

        $user = User::LoginByToken((new Backend), $token);
    
        $elements = Element::list($this->collectionName, $user->backend, [
            'take' => -1,
            'where' => [
                'checkIn' => true,
                'isPublished' => [
                    '$in' => [true, false]
                ]
            ]
        ])->map(function ($item) {
            return [
                'id' => $item->id,
                'title' => $item->fields['title'] ?? '',
                'beginAt' => isset($item->fields['beginAt'])
                    ? (new Carbon($item->fields['beginAt']))->setTimezone('UTC')->format('d.m.Y H:i')
                    : ''
            ];
        });

        $results = [];

        foreach ($elements as $element) {
            QRCode::text($this->qrText($this->collectionName, $element['id']))->setOutfile(storage_path('qr/' . $element['id'] . '.png'))->png();
            $results [] = [
                'svg' =>  base64_encode(file_get_contents(storage_path('qr/' . $element['id'] . '.png'))),
                'id' => $element['id'],
                'title' => $element['title'],
                'beginAt' => $element['beginAt']
            ];

            unlink(storage_path('qr/' . $element['id'] . '.png'));
        }

        $pdf = DomPDF::loadView('qr.list', [
            'elements' => $results,
            'collection' => $this->collectionName
        ])->save(storage_path('qr/list.pdf'));

        Mail::to($this->email)->send(new QrMail(storage_path('qr/list.pdf')));

        unlink(storage_path('qr/list.pdf'));

        $this->info('All done');
    }
}
