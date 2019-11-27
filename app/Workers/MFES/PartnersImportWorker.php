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
        $tags = Element::list('StandTags', $this->user->backend, [
            'take' => -1,
            'where' => [
                'isPublished' => [
                    '$in' => [true, false]
                ]
            ],
            'include' => ['id', 'title', 'createdAt', 'updatedAt', 'ownerId', 'isPublished']
        ])->mapWithKeys(function(Element $tag) {
            $key = str_replace(' ', '', $tag->fields['title']);
            $key = mb_strtolower($key);
            return [$key => $tag];
        });

        $exponents = Element::list('Partners', $this->user->backend, [
            'take' => -1,
            'where' => [
                'isPublished' => [
                    '$in' => [true, false]
                ]
            ],
            'include' => ['id', 'title', 'createdAt', 'updatedAt', 'ownerId', 'svgId', 'externalUpdatedAt', 'isPublished']
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

        $fetchedTags = [];
        foreach ($fetchedExponents as $exponent) {
            if (isset($exponent->SECTION) && is_array($exponent->SECTION)) {
                foreach ($exponent->SECTION as $section) {
                    $key = str_replace(' ', '', $section);
                    $key = mb_strtolower($key);
                    $fetchedTags[$key] = $section;
                }
            }
        }

        foreach ($fetchedTags as $key => $tag) {
            if (!$tags->has($key)) {
                $newTag = Element::create('StandTags', [
                    'title' => $tag
                ], $this->user->backend);

                $tags[$key] = $newTag;
            }
        }

        foreach ($fetchedExponents as $exponent) {
            $externalId = $exponent->ID;

            $subtitle = '';
            if (isset($exponent->SECTION) && is_array($exponent->SECTION)) {
                $subtitle = implode('; ', $exponent->SECTION);
            }

            $clearSite = str_replace(['http://', 'https://'], '', $exponent->LINK ?? '');

            $description = (isset($exponent->ABOUT_RUS) && is_string($exponent->ABOUT_RUS)) 
                ? str_replace("\r\n", "<br/>\r\n", trim(htmlspecialchars_decode($exponent->ABOUT_RUS ?? '')))
                : str_replace("\r\n", "<br/>\r\n", trim(htmlspecialchars_decode($exponent->ABOUT_RUS->TEXT ?? '')));

            $description = view('mfes/exponents', [
                'title' => trim(htmlspecialchars_decode($exponent->ORG_NAME)),
                'subtitle' => trim(htmlspecialchars_decode($subtitle)),
                'description' => $description,
                'site' => $exponent->LINK ?? '',
                'clearSite' => $clearSite,
                'address' => $exponent->ADRES ?? '',
                'phone' => $exponent->PHONE ?? '',
                'email' => $exponent->EMAIL ?? '',
                'svgId' => $externalId,
            ])->render();

            if (isset($exponent->ABOUT_ENG->TEXT)) {
                $descriptionEn = (isset($exponent->ABOUT_ENG) && is_string($exponent->ABOUT_ENG)) 
                    ? str_replace("\r\n", "<br/>\r\n", trim(htmlspecialchars_decode($exponent->ABOUT_ENG ?? '')))
                    : str_replace("\r\n", "<br/>\r\n", trim(htmlspecialchars_decode($exponent->ABOUT_ENG->TEXT ?? '')));
                    
                $descriptionEn = view('mfes/exponents', [
                    'title' => trim(htmlspecialchars_decode($exponent->ORG_NAME)),
                    'subtitle' => trim(htmlspecialchars_decode($subtitle)),
                    'description' => $descriptionEn,
                    'site' => $exponent->LINK ?? '',
                    'clearSite' => $clearSite,
                    'address' => $exponent->ADRES ?? '',
                    'phone' => $exponent->PHONE ?? '',
                    'email' => $exponent->EMAIL ?? '',
                    'svgId' => $externalId,
                ])->render();
            } else {
                $descriptionEn = null;
            }

            $exponentTags = [];
            if (isset($exponent->SECTION) && is_array($exponent->SECTION)) {
                foreach ($exponent->SECTION as $section) {
                    $key = str_replace(' ', '', $section);
                    $key = mb_strtolower($key);

                    $exponentTags[] = $tags[$key]->id;
                }
            }

            if (!$exponents->has($externalId)) {
                
                $newPartner = Element::create('Partners', [
                    'title' => trim(htmlspecialchars_decode($exponent->ORG_NAME)) ?? '',
                    'tagsIds' => $exponentTags,
                    'description' => $description,
                    'svgId' => $externalId,
                    'externalUpdatedAt' => $exponent->UPDATE_TIME
                ], $this->user->backend);

                Element::updateLanguages('Partners', $newPartner->id, [
                    'en' => [
                        'description' => $descriptionEn
                    ]
                ], $this->user->backend);

                $this->log('Successfully created stand: ' . ($exponent->ORG_NAME ?? '') . ' (https://web.appercode.com/electroseti/Partners/' . $newPartner->id . '/edit)');
            } elseif ($exponents[$externalId]->fields['externalUpdatedAt'] != $exponent->UPDATE_TIME) {
                Element::update('Partners', $exponents[$externalId]->id, [
                    'title' => trim(htmlspecialchars_decode($exponent->ORG_NAME)) ?? '',
                    'subtitle' => '',
                    'tagsIds' => $exponentTags,
                    'description' => $description,
                    'externalUpdatedAt' => $exponent->UPDATE_TIME
                ], $this->user->backend);

                Element::updateLanguages('Partners', $exponents[$externalId]->id, [
                    'en' => [
                        'description' => $descriptionEn
                    ]
                ], $this->user->backend);

                $this->log('Successfully updated stand: ' . ($exponent->ORG_NAME ?? '') . ' (https://web.appercode.com/electroseti/Partners/' . $exponents[$externalId]->id . '/edit)');
            } else {
                $this->log('Skipped stand: ' . ($exponent->ORG_NAME ?? '') . ' (https://web.appercode.com/electroseti/Partners/' . $exponents[$externalId]->id . '/edit)');
            }
        }

        foreach ($exponents as $stand) {
            if ($stand->fields['isPublished'] && !isset($fetchedExponents[$stand->fields['svgId']])) {
                Element::update('Partners', $stand->id, [
                    'isPublished' => false,
                ], $this->user->backend);

                $this->log('Unpublished stand: ' . ($stand->fields['title'] ?? '') . ' (https://web.appercode.com/electroseti/Partners/' . $stand->id . '/edit)');
            }
            if (!$stand->fields['isPublished'] && isset($fetchedExponents[$stand->fields['svgId']])) {
                Element::update('Partners', $stand->id, [
                    'isPublished' => true,
                ], $this->user->backend);

                $this->log('Published stand: ' . ($stand->fields['title'] ?? '') . ' (https://web.appercode.com/electroseti/Partners/' . $stand->id . '/edit)');
            }
        }

        foreach ($tags as $tag) {
            $key = str_replace(' ', '', $tag->fields['title']);
            $key = mb_strtolower($key);

            if ($tag->fields['isPublished'] && !isset($fetchedTags[$key])) {
                Element::update('StandTags', $tag->id, [
                    'isPublished' => false,
                ], $this->user->backend);

                $this->log('Unpublished tag: ' . ($tag->fields['title'] ?? '') . ' (https://web.appercode.com/electroseti/StandTags/' . $tag->id . '/edit)');
            }
            if (!$tag->fields['isPublished'] && isset($fetchedTags[$key])) {
                Element::update('StandTags', $tag->id, [
                    'isPublished' => true,
                ], $this->user->backend);

                $this->log('Published tag: ' . ($tag->fields['title'] ?? '') . ' (https://web.appercode.com/electroseti/StandTags/' . $tag->id . '/edit)');
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
                    'address' => $partner->ADDRESS ?? ''
                ])->render();

                $newPartner = Element::create('RealPartners', [
                    'title' => trim(htmlspecialchars_decode($partner->NAME)) ?? '',
                    'description' => $description,
                    'imageFileId' => $fileId,
                    'svgId' => $partner->STAND ?? '',
                    'site' => $partner->LINK ?? '',
                    'address' => $partner->ADDRESS ?? '',
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
                    'address' => $partner->ADDRESS ?? ''
                ])->render();

                Element::update('RealPartners', $partners[$externalId]->id, [
                    'title' => trim(htmlspecialchars_decode($partner->NAME)) ?? '',
                    'description' => $description,
                    'imageFileId' => $fileId,
                    'svgId' => $partner->STAND ?? '',
                    'site' => $partner->LINK ?? '',
                    'address' => $partner->ADDRESS ?? '',
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
