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

class ImportWorker extends BaseWorker
{
    const BASE_URL = 'https://expoelectroseti.ru';
    const NEWS_LIST_URL = 'https://expoelectroseti.ru/app/news.php';
    const NEWS_DETAIL_URL = 'https://expoelectroseti.ru/app/news.php?ELEMENT_ID=';

    const NEWS_FILE_FOLDER = '9386d36f-088c-482c-8d7b-6f235edcbce2';

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
            'parentId' => self::NEWS_FILE_FOLDER,
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

    private function news()
    {
        $news = [];

        $data = file_get_contents(self::NEWS_LIST_URL);
        $data = json_decode($data);

        $tags = Element::list('NewsTags', $this->user->backend, [
            'take' => -1
        ])->mapWithKeys(function(Element $element) {
            return [$element->fields['externalId'] => $element];
        });

        foreach ($data->SECTIONS as $section) {
            if (! $tags->has($section->ID)) {
                $tag = Element::create('NewsTags', [
                    'title' => $section->NAME,
                    'externalId' => $section->ID
                ], $this->user->backend);

                $tags[$section->ID] = $tag;
            }
        }

        $pagesCount = $data->NAV->NavPageCount;
        $currentPage = 0;
        do {
            $news = array_merge($data->ITEMS, $news);
            $currentPage++;
            $data = file_get_contents(self::NEWS_LIST_URL . '?PAGEN_1=' . ($currentPage + 1));
            $data = json_decode($data);
        } while ($pagesCount > $currentPage);

        $this->log('Fetched ' . count($news) . ' news');

        $existedNews = Element::list('News', $this->user->backend, [
            'take' => -1,
            'include' => ['id', 'externalId', 'externalUpdatedAt', 'createdAt', 'updatedAt', 'ownerId']
        ])->mapWithKeys(function(Element $element) {
            return [$element->fields['externalId'] => $element];
        });
        
        foreach ($news as $one) {
            if ($existedNews->has($one->ID)) {
                $existed = $existedNews->get($one->ID);
                if ($one->UPDATE_TIME != $existed->fields['externalUpdatedAt']) {

                    $file = $this->uploadFile($one->PREVIEW_PICTURE, self::NEWS_FILE_FOLDER, $one->NAME);

                    $extended = file_get_contents(self::NEWS_DETAIL_URL . $one->ID);
                    $extended = json_decode($extended);

                    $tagIds = [];

                    if (isset($extended->SECTIONS)) {
                        foreach ($extended->SECTIONS as $section) {
                            if ($tags->has($section->ID)) {
                                $tagIds[] = $tags->get($section->ID)->id;
                            }
                        }
                    }

                    Element::update('News', $existedNews->get($one->ID)->id, [
                        'title' => $one->NAME,
                        'externalId' => $one->ID,
                        'publishedAt' => Carbon::parse($one->ACTIVE_FROM, 'Europe/Moscow')->toAtomString(),
                        'imageFileId' => $file->id,
                        'description' => $extended->DETAIL_TEXT,
                        'tagsIds' => $tagIds,
                        'externalUpdatedAt' => $one->UPDATE_TIME
                    ], $this->user->backend);
                    
                    $this->log('Updated news: https://web.appercode.com/electroseti/News/' . $existed->id . '/edit');
                } else {
                    $this->log('Passed news: https://web.appercode.com/electroseti/News/' . $existed->id . '/edit');
                }
            } else {

                $file = $this->uploadFile($one->PREVIEW_PICTURE, self::NEWS_FILE_FOLDER, $one->NAME);

                $extended = file_get_contents(self::NEWS_DETAIL_URL . $one->ID);
                $extended = json_decode($extended);

                $tagIds = [];

                if (isset($extended->SECTIONS)) {
                    foreach ($extended->SECTIONS as $section) {
                        if ($tags->has($section->ID)) {
                            $tagIds[] = $tags->get($section->ID)->id;
                        }
                    }
                }

                $element = Element::create('News', [
                    'title' => $one->NAME,
                    'externalId' => $one->ID,
                    'publishedAt' => Carbon::parse($one->ACTIVE_FROM, 'Europe/Moscow')->toAtomString(),
                    'imageFileId' => $file->id,
                    'description' => $extended->DETAIL_TEXT,
                    'tagsIds' => $tagIds,
                    'externalUpdatedAt' => $one->UPDATE_TIME
                ], $this->user->backend);

                $this->log('Created news: https://web.appercode.com/electroseti/News/' . $element->id . '/edit');
            }
        }

        $this->log('News imported successfully');
    }

    public function handle()
    {
        $this->news();
    }
}
