<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use Appercode\Element;
use Appercode\User;
use Appercode\Backend;

class FillUpTransfer extends Command
{
    private $user;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transfer:import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Imports transfer elements from csv file';

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

        $this->user = User::LoginByToken((new Backend), $token);

        $elements = [];
        $file = fopen(storage_path('transfer.csv'), 'r');
        while (($line = fgetcsv($file, 99999, ';')) !== false) {
            $elements[] = [
                'title' => $line[0] ?? '',
                'comment' => $line[1] ?? '',
                'departure' => $line[2] ?? '',
                'departure2' => $line[3] ?? '',
                'sorting' => $line[4] ?? null
            ];
        }

        fclose($file);
        

        foreach ($elements as $element) {
            $existedElement = Element::list('Transfer', $this->user->backend, [
                'where' => [
                    'title' => $element['title']
                ]
            ])->first();

            if (!is_null($element['sorting']) && !is_null($existedElement)) {
                Element::update('Transfer', $existedElement->id, [
                    'orderIndex' => (int) $element['sorting']
                ], $this->user->backend);
            }

            if (!is_null($existedElement)) {
                $html = view('transfer/description', [
                    'title' => $element['title'],
                    'comment' => $element['comment'],
                    'departure' => $element['departure'],
                    'departure2' => $element['departure2'],
                    'image' => $existedElement->fields['imageFileId'] ?? null
                ])->render();

                Element::update('Transfer', $existedElement->id, [
                    'description' => $html
                ], $this->user->backend);
            }
        }

        $this->info('All done');
    }
}
