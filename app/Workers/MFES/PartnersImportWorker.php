<?php

namespace App\Workers\MFES;

use App\Workers\BaseWorker;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;
use Appercode\File;

use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

use Carbon\Carbon;

class PartnersImportWorker extends BaseWorker
{
    const BASE_URL = 'https://expoelectroseti.ru';
    const STANDS_LIST_URL = 'https://expoelectroseti.ru/app/stands.php';
    const PARTNERS_LIST_URL = 'https://expoelectroseti.ru/app/partners.php';

    const PARTNERS_FILE_FOLDER = 'b5d5b488-037b-4f88-a803-cb072beb0b7c';

    private $now;

    public function __construct($user, $logger)
    {
        parent::__construct($user, $logger);

        $this->now = (new Carbon())->setTimezone('Europe/Moscow');
    }

    private function uploadFile($url, $parentId, $name): File
    {
        $fileName = $url;
        $fileName = explode('/', $fileName);
        $fileName = $fileName[count($fileName) - 1];

        $fileFields = [
            'parentId' => self::PARTNERS_FILE_FOLDER,
            'name' => $name,
            'isFile' => true,
            'shareStatus' => 'shared',
            'rights' => [
                'READ' => true
            ]
        ];

        $multipart = [
            [
                'name' => 'file',
                'filename' => $fileName,
                'contents' => file_get_contents(self::BASE_URL . $url),
            ]
        ];

        $file = File::create($fileFields, $this->user->backend);
        $file->upload($multipart);

        return $file;
    }

    private function stands()
    {
        $exponents = Element::list('Partners', $this->user->backend, [
            'take' => -1,
            'where' => [
                'isPublished' => [
                    '$in' => [true, false]
                ]
            ],
            'include' => ['id', 'title', 'createdAt', 'updatedAt', 'ownerId', 'svgId', 'externalUpdatedAt']
        ])->mapWithKeys(function(Element $exponent) {
            return [$exponent->fields['svgId'] => $exponent];
        });

        $data = file_get_contents(self::STANDS_LIST_URL);
        $data = json_decode($data);

        $fetchedExponents = [];
        foreach ($data as $stand) {
            $fetchedExponents[$stand->ID] = $stand;
        }

        $this->log('Fetched ' . count($data) . ' stands');

        foreach ($fetchedExponents as $exponent) {
            $externalId = $exponent->ID;

            $description = '<p></p><br><p><a data-link-generator="" data-schema-id="HtmlPages" data-object-id="b16cc295-afec-47a9-a878-373663ba7e48" href="html:?schemaId=HtmlPages&amp;objectId=b16cc295-afec-47a9-a878-373663ba7e48&amp;message=' . $externalId . '">Посмотреть на схеме выставки &gt;</a></p>';

            $description = '';

            $subtitle = '';
            if (isset($exponent->SECTION) && is_array($exponent->SECTION)) {
                $subtitle = implode(', ', $exponent->SECTION ?? []);
            }

            if (!$exponents->has($externalId)) {
                
                $newPartner = Element::create('Partners', [
                    'title' => $exponent->ORG_NAME ?? '',
                    'subtitle' => $subtitle,
                    'description' => $description,
                    'svgId' => $externalId,
                    'externalUpdatedAt' => $exponent->UPDATE_TIME
                ], $this->user->backend);

                $this->log('Successfully created stand: ' . ($exponent->ORG_NAME ?? '') . ' (https://web.appercode.com/electroseti/Partners/' . $newPartner->id . '/edit)');
            } elseif ($exponents[$externalId]->fields['externalUpdatedAt'] != $exponent->UPDATE_TIME) {
                Element::update('Partners', $exponents[$externalId]->id, [
                    'title' => $exponent->ORG_NAME ?? '',
                    'subtitle' => $subtitle,
                    'description' => $description,
                    'externalUpdatedAt' => $exponent->UPDATE_TIME
                ], $this->user->backend);

                $this->log('Successfully updated stand: ' . ($exponent->ORG_NAME ?? '') . ' (https://web.appercode.com/electroseti/Partners/' . $exponents[$externalId]->id . '/edit)');
            } else {
                $this->log('Skipped stand: ' . ($exponent->ORG_NAME ?? '') . ' (https://web.appercode.com/electroseti/Partners/' . $exponents[$externalId]->id . '/edit)');
            }
        }

        $this->log('Stands imported successfully');
    }

    public function partners()
    {
        $partners = Element::list('RealPartners', $this->user->backend, [
            'take' => -1,
            'where' => [
                'isPublished' => [
                    '$in' => [true, false]
                ],
                'externalId' => [
                    '$exists' => true
                ]
            ],
            'include' => ['id', 'title', 'createdAt', 'updatedAt', 'ownerId', 'svgId', 'externalUpdatedAt', 'externalId', 'isPublished']
        ])->mapWithKeys(function(Element $partner) {
            return [$partner->fields['externalId'] => $partner];
        });

        $data = file_get_contents(self::PARTNERS_LIST_URL);
        $data = json_decode($data);

        $fetchedPartners = [];
        foreach ($data as $partner) {
            $fetchedPartners[$partner->ID] = $partner;
        }

        $this->log('Fetched ' . count($data) . ' partners');

        foreach ($fetchedPartners as $partner) {
            $externalId = $partner->ID;

            $clearSite = str_replace(['http://', 'https://'], '', $partner->LINK ?? '');
            $description = $partner->DETAIL_TEXT ?? '';
            $description = str_replace('style="text-align: justify;"', '', $description);

            if (!$partners->has($externalId)) {
                $fileId = null;
                if (isset($partner->DETAIL_PICTURE) && $partner->DETAIL_PICTURE) {
                    $fileId = $this->uploadFile($partner->DETAIL_PICTURE, self::PARTNERS_FILE_FOLDER, $partner->NAME ?? 'partner-' . $externalId)->id;
                }

                $description = view('mfes/partners', [
                    'title' => trim(htmlspecialchars_decode($partner->NAME)) ?? '',
                    'imageFileId' => $fileId,
                    'description' => $description,
                    'section' => $partner->SECTION ?? '',
                    'svgId' => $partner->STAND ?? '',
                    'site' => $partner->LINK ?? '',
                    'clearSite' => $clearSite,
                    'phone' => $partner->PHONE ?? '',
                    'email' => $partner->EMAIL ?? '',
                    'address' => $partner->ADRES ?? ''
                ])->render();

                $newPartner = Element::create('RealPartners', [
                    'title' => trim(htmlspecialchars_decode($partner->NAME)) ?? '',
                    'description' => $description,
                    'imageFileId' => $fileId,
                    'svgId' => $partner->STAND ?? '',
                    'site' => $partner->LINK ?? '',
                    'address' => $partner->ADRES ?? '',
                    'phone' => $partner->PHONE ?? '',
                    'email' => $partner->EMAIL ?? '',
                    'section' => $partner->SECTION ?? '',
                    'orderIndex' => $partner->SORT ?? null,
                    'externalUpdatedAt' => $partner->UPDATE_TIME ?? '',
                    'externalId' => $externalId
                ], $this->user->backend);

                $this->log('Successfully created partner: ' . ($partner->NAME ?? '') . ' (https://web.appercode.com/electroseti/RealPartners/' . $newPartner->id . '/edit)');
            } elseif ($partners[$externalId]->fields['externalUpdatedAt'] != $partner->UPDATE_TIME) {
                $fileId = null;
                if (isset($partner->DETAIL_PICTURE) && $partner->DETAIL_PICTURE) {
                    $fileId = $this->uploadFile($partner->DETAIL_PICTURE, self::PARTNERS_FILE_FOLDER, $partner->NAME ?? 'partner-' . $externalId)->id;
                }

                $description = view('mfes/partners', [
                    'title' => trim(htmlspecialchars_decode($partner->NAME)) ?? '',
                    'imageFileId' => $fileId,
                    'description' => $description,
                    'section' => $partner->SECTION ?? '',
                    'svgId' => $partner->STAND ?? '',
                    'site' => $partner->LINK ?? '',
                    'clearSite' => $clearSite,
                    'phone' => $partner->PHONE ?? '',
                    'email' => $partner->EMAIL ?? '',
                    'address' => $partner->ADRES ?? ''
                ])->render();

                Element::update('RealPartners', $partners[$externalId]->id, [
                    'title' => trim(htmlspecialchars_decode($partner->NAME)) ?? '',
                    'description' => $description,
                    'imageFileId' => $fileId,
                    'svgId' => $partner->STAND ?? '',
                    'site' => $partner->LINK ?? '',
                    'address' => $partner->ADRES ?? '',
                    'phone' => $partner->PHONE ?? '',
                    'email' => $partner->EMAIL ?? '',
                    'section' => $partner->SECTION ?? '',
                    'orderIndex' => $partner->SORT ?? null,
                    'externalUpdatedAt' => $partner->UPDATE_TIME ?? ''
                ], $this->user->backend);

                $this->log('Successfully updated partner: ' . ($partner->NAME ?? '') . ' (https://web.appercode.com/electroseti/RealPartners/' . $partners[$externalId]->id . '/edit)');
            } else {
                $this->log('Skipped partner: ' . ($partner->NAME ?? '') . ' (https://web.appercode.com/electroseti/RealPartners/' . $partners[$externalId]->id . '/edit)');
            }
        }

        foreach ($partners as $partner) {
            if ($partner->fields['isPublished'] && !isset($fetchedPartners[$partner->fields['externalId']])) {
                Element::update('RealPartners', $partner->id, [
                    'isPublished' => false,
                ], $this->user->backend);

                $this->log('Unpublished partner: ' . ($partner->fields['title'] ?? '') . ' (https://web.appercode.com/electroseti/RealPartners/' . $partner->id . '/edit)');
            }
            if (!$partner->fields['isPublished'] && isset($fetchedPartners[$partner->fields['externalId']])) {
                Element::update('RealPartners', $partner->id, [
                    'isPublished' => true,
                ], $this->user->backend);

                $this->log('Published partner: ' . ($partner->fields['title'] ?? '') . ' (https://web.appercode.com/electroseti/RealPartners/' . $partner->id . '/edit)');
            }
        }

        $this->log('Stands imported successfully');
    }

    public function handle()
    {
        $this->stands();
        $this->partners();
    }
}
