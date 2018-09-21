<?php

namespace Tests\Unit;

use Tests\TestCase;

use Appercode\User;
use Appercode\Backend;
use Appercode\Element;

use App\Workers\EventsCreator;

use Carbon\Carbon;

class EventsCreatorTest extends TestCase
{
    const USER_PROFILES_COLLECTION = 'UserProfiles';
    
    private $user;

    protected function setUp()
    {
        parent::setUp();
        $this->user = User::LoginByToken((new Backend), env('TOKEN'));
    }

    private function getProfiles(): array
    {
        return Element::list(self::USER_PROFILES_COLLECTION, $this->user->backend, [
            'take' => 3
        ])->map(function ($item) {
            return $item->id;
        })->toArray();
    }

    public function test_empty_evens_section_can_not_be_processed()
    {
        $eventSection = Element::create(EventsCreator::EVENTS_COLLECTION, [
            'title' => 'deleteMePls'
        ], $this->user->backend);

        $eventsCreator = new EventsCreator($this->user);
        $eventsCreator->handle();

        $childEvents = Element::list(EventsCreator::EVENTS_COLLECTION, $this->user->backend, [
            'take' => -1,
            'where' => [
                'parentId' => $eventSection->id
            ]
        ]);

        $eventSection->delete();

        $this->assertEquals($childEvents->count(), 0);
    }

    public function test_event_can_be_created_with_correct_fields()
    {
        $reports = [];
        $userProfiles = $this->getProfiles();

        for ($i = 0; $i < 3; $i++) {
            $report = Element::create(EventsCreator::REPORTS_COLLECTION, [
                'title' => "title$i",
                'description' => "description$i",
                'userProfileIds' => $userProfiles,
            ], $this->user->backend);

            Element::updateLanguages(EventsCreator::REPORTS_COLLECTION, $report->id, [
                'en' => [
                    'title' => "enTitle$i",
                    'description' => "enDescription$i"
                ],
            ], $this->user->backend);

            $reports[] = $report->getLanguages('en');
        }

        $reportsIds = collect($reports)
            ->map(function ($item) {
                return $item->id;
            })
            ->toArray();

        $eventSection = Element::create(EventsCreator::EVENTS_COLLECTION, [
            'title' => 'deleteMePls',
            'reports' => $reportsIds,
            'beginAt' => (new Carbon)->setTimezone('Europe/Moscow')->toAtomString(),
            'endAt' => (new Carbon)->addHour()->setTimezone('Europe/Moscow')->toAtomString()
        ], $this->user->backend);

        $eventsCreator = new EventsCreator($this->user);
        $eventsCreator->handle();

        $createdEvents = Element::list(EventsCreator::EVENTS_COLLECTION, $this->user->backend, [
            'take' => -1,
            'where' => [
                'externalId' => [
                    '$in' => $reportsIds
                ]
            ]
        ])->mapWithKeys(function ($event) {
            return [$event->fields['externalId'] => $event];
        });

        $this->assertEquals($createdEvents->count(), 3);

        foreach ($reports as $report) {
            $event = $createdEvents[$report->id];
            $this->assertEquals($event->fields['title'], $report->fields['title']);
            $this->assertEquals($event->fields['description'], $report->fields['description']);
            $this->assertEquals($event->fields['externalId'], $report->id);
            $this->assertEquals($event->fields['parentId'], $eventSection->id);
            $this->assertEquals($event->fields['participantsIds'], $report->fields['userProfileIds']);
            $this->assertEquals($event->fields['beginAt'], $eventSection->fields['beginAt']);
            $this->assertEquals($event->fields['endAt'], $eventSection->fields['endAt']);
            $this->assertEquals($event->fields['groupIds'], [EventsCreator::MAIN_GROUP_ID]);

            $event->getLanguages('en');

            $this->assertEquals($event->languages['en']['title'], $report->languages['en']['title']);
            $this->assertEquals($event->languages['en']['description'], $report->languages['en']['description']);
        }

        foreach ($reports as $report) {
            $report->delete();
        }
        foreach ($createdEvents as $event) {
            $event->delete();
        }
        $eventSection->delete();
    }

    public function test_events_section_has_empty_reports_field_after_processing()
    {
        $reports = [];
        $userProfiles = $this->getProfiles();

        for ($i = 0; $i < 1; $i++) {
            $report = Element::create(EventsCreator::REPORTS_COLLECTION, [
                'title' => "title$i",
                'description' => "description$i",
                'userProfileIds' => $userProfiles,
            ], $this->user->backend);

            Element::updateLanguages(EventsCreator::REPORTS_COLLECTION, $report->id, [
                'en' => [
                    'title' => "enTitle$i",
                    'description' => "enDescription$i"
                ],
            ], $this->user->backend);

            $reports[] = $report->getLanguages('en');
        }

        $reportsIds = collect($reports)
            ->map(function ($item) {
                return $item->id;
            })
            ->toArray();

        $eventSection = Element::create(EventsCreator::EVENTS_COLLECTION, [
            'title' => 'deleteMePls',
            'reports' => $reportsIds,
            'beginAt' => (new Carbon)->setTimezone('Europe/Moscow')->toAtomString(),
            'endAt' => (new Carbon)->addHour()->setTimezone('Europe/Moscow')->toAtomString()
        ], $this->user->backend);

        $eventsCreator = new EventsCreator($this->user);
        $eventsCreator->handle();

        $eventSection = Element::find(EventsCreator::EVENTS_COLLECTION, $eventSection->id, $this->user->backend);

        $this->assertNull($eventSection->fields['reports']);

        foreach ($reports as $report) {
            $report->delete();
        }
        $eventSection->delete();
    }

    public function test_events_creator_removes_header_tag_correctly()
    {
        $eventsCreator = new EventsCreator($this->user);

        $testString = '<h3>Header to remove</h3><h3>Header to keep</h3><p>Some text</p><h3>Another header to keep</h3><p>Text</p><p>Text</p>';

        $processedString = $eventsCreator->removeHeaderTag($testString);

        $this->assertEquals($processedString, '<h3>Header to keep</h3><p>Some text</p><h3>Another header to keep</h3><p>Text</p><p>Text</p>');
    }
}
