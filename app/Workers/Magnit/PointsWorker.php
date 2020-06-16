<?php

namespace App\Workers\Magnit;

use App\Workers\BaseWorker;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;
use Appercode\Points;

use Illuminate\Support\Collection;

use Carbon\Carbon;

class PointsWorker extends BaseWorker
{
    private function shopReports()
    {
        $elements = Element::list('ShopReports', $this->user->backend, [
            'where' => [
                'pointsAccrualId' => [
                    '$exists' => false
                ],
                'isChecked' => true
            ],
            'take' => -1
        ]);

        foreach ($elements as $element) {
            if (isset($element->ownerId) && $element->ownerId) {

                if ((isset($element->fields['positivePhotoIds']) && $element->fields['positivePhotoIds']) || (isset($element->fields['negativePhotoIds']) && $element->fields['negativePhotoIds'])) {

                    $previous = Element::list('ShopReports', $this->user->backend, [
                        'where' => [
                            'isChecked' => true,
                            'ownerId' => $element->ownerId,
                            'shopId' => $element->fields['shopId'],
                            'createdAt' => [
                                '$lte' => Carbon::now()->subDays(14)->toAtomString()
                            ]
                        ],
                        'take' => -1
                    ])->first();

                    if (!is_null($previous)) {
                        $title = 'За отзыв о магазине (работа над ошибками)';
                        $title_en = 'For store second review';
                        $amount = 100;
                    } else {
                        $title = 'За отзыв о магазине';
                        $title_en = 'For store review';
                        $amount = 70;
                    }

                    $points = Points::create($this->user->backend, [
                        'usersIds' => [$element->ownerId],
                        'withNotification' => true,
                        'title' => [
                            'ru' => $title,
                            'en' => $title_en
                        ],
                        'amount' => $amount,
                        'category' => 'manual'
                    ]);

                    Element::update('ShopReports', $element->id, [
                        'pointsAccrualId' => $points->id
                    ], $this->user->backend);
                }
            }
        }
    }

    private function ideaReports()
    {
        $elements = Element::list('IdeaReports', $this->user->backend, [
            'where' => [
                'pointsAccrualId' => [
                    '$exists' => false
                ],
                'isChecked' => true
            ],
            'take' => -1
        ]);

        foreach ($elements as $element) {
            if (isset($element->ownerId) && $element->ownerId) {

                $points = Points::create($this->user->backend, [
                    'usersIds' => [$element->ownerId],
                    'withNotification' => true,
                    'title' => [
                        'ru' => 'За предложенную идею',
                        'en' => 'For the proposed idea'
                    ],
                    'amount' => 100,
                    'category' => 'manual'
                ]);

                Element::update('IdeaReports', $element->id, [
                    'pointsAccrualId' => $points->id
                ], $this->user->backend);
            }
        }
    }

    private function appeals()
    {
        $elements = Element::list('Appeals', $this->user->backend, [
            'where' => [
                'pointsAccrualId' => [
                    '$exists' => false
                ],
                'isChecked' => true
            ],
            'take' => -1
        ]);

        foreach ($elements as $element) {
            if (isset($element->ownerId) && $element->ownerId) {

                $previous = Element::list('Appeals', $this->user->backend, [
                    'where' => [
                        'pointsAccrualId' => [
                            '$exists' => false
                        ],
                        'isChecked' => true,
                        'ownerId' => $element->ownerId,
                        'id' => [
                            '$ne' => $element->id
                        ]
                    ],
                    'take' => -1
                ]);

                if (!$previous->count()) {
                    $points = Points::create($this->user->backend, [
                        'usersIds' => [$element->ownerId],
                        'withNotification' => true,
                        'title' => [
                            'ru' => 'За отзыв SOS',
                            'en' => 'For the SOS report'
                        ],
                        'amount' => 100,
                        'category' => 'manual'
                    ]);

                    Element::update('Appeals', $element->id, [
                        'pointsAccrualId' => $points->id
                    ], $this->user->backend);
                }
            }
        }
    }

    public function handle()
    {
        $this->shopReports();
        $this->ideaReports();
        $this->appeals();
    }
}
