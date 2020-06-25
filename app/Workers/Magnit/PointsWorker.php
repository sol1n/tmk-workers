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
                $points = Points::create($this->user->backend, [
                    'usersIds' => [$element->ownerId],
                    'withNotification' => true,
                    'title' => [
                        'ru' => 'За отзыв о магазине',
                        'en' => 'For store review'
                    ],
                    'amount' => 35,
                    'category' => 'manual'
                ]);

                Element::update('ShopReports', $element->id, [
                    'pointsAccrualId' => $points->id
                ], $this->user->backend);
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
                    'amount' => 20,
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

                $points = Points::create($this->user->backend, [
                    'usersIds' => [$element->ownerId],
                    'withNotification' => true,
                    'title' => [
                        'ru' => 'За отзыв SOS',
                        'en' => 'For the SOS report'
                    ],
                    'amount' => 25,
                    'category' => 'manual'
                ]);

                Element::update('Appeals', $element->id, [
                    'pointsAccrualId' => $points->id
                ], $this->user->backend);
            }
        }
    }


    private function news()
    {
        $elements = Element::list('OurMGN', $this->user->backend, [
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
                        'ru' => 'За предложенную новость',
                        'en' => 'For the proposed news'
                    ],
                    'amount' => 15,
                    'category' => 'manual'
                ]);

                Element::update('OurMGN', $element->id, [
                    'pointsAccrualId' => $points->id
                ], $this->user->backend);
            }
        }
    }

    public function handle()
    {
        $this->shopReports();
        $this->ideaReports();
        $this->appeals();
        $this->news();
    }
}
