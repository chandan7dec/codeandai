<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TrainingResourceServiceTest extends \Tests\Support\DatabaseTestCase
{
    private \TrainingResourceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new \TrainingResourceService();
    }

    // ── URL parsing ──

    public function testParseYouTubeIdAcceptsAllCommonUrlShapes(): void
    {
        $cases = [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ'      => 'dQw4w9WgXcQ',
            'https://youtube.com/watch?v=dQw4w9WgXcQ&t=90s'    => 'dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ'                     => 'dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ?t=42'                => 'dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ'       => 'dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ'        => 'dQw4w9WgXcQ',
            'https://youtube.com/live/dQw4w9WgXcQ'             => 'dQw4w9WgXcQ',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ'        => 'dQw4w9WgXcQ',
        ];
        foreach ($cases as $url => $expected) {
            $this->assertSame($expected, $this->service->parseYouTubeId($url), "Failed for: $url");
        }
    }

    public function testParseYouTubeIdRejectsInvalidInput(): void
    {
        $cases = [
            '',
            'https://vimeo.com/123456789',
            'https://youtube.com/watch?v=tooshort',
            'https://example.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/',
        ];
        foreach ($cases as $url) {
            $this->assertNull($this->service->parseYouTubeId($url), "Should reject: $url");
        }
    }

    public function testParseDriveUrlExtractsFileIdAndBuildsDownloadUrl(): void
    {
        $parsed = $this->service->parseDriveUrl(
            'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz1234567890/view?usp=sharing'
        );
        $this->assertNotNull($parsed);
        $this->assertSame('1AbCdEfGhIjKlMnOpQrStUvWxYz1234567890', $parsed['drive_file_id']);
        $this->assertSame(
            'https://drive.google.com/uc?export=download&id=1AbCdEfGhIjKlMnOpQrStUvWxYz1234567890',
            $parsed['download_url']
        );
    }

    public function testParseDriveUrlAcceptsOpenAndDownloadForms(): void
    {
        $open = $this->service->parseDriveUrl('https://drive.google.com/open?id=1AbCdEfGhIjKlMnOp');
        $this->assertNotNull($open);
        $this->assertSame('1AbCdEfGhIjKlMnOp', $open['drive_file_id']);

        $direct = $this->service->parseDriveUrl('https://drive.google.com/uc?export=download&id=1AbCdEfGhIjKlMnOp');
        $this->assertNotNull($direct);
        $this->assertSame('1AbCdEfGhIjKlMnOp', $direct['drive_file_id']);
    }

    public function testParseDriveUrlRejectsNonDriveLinks(): void
    {
        $this->assertNull($this->service->parseDriveUrl('https://example.com/file/d/xxx/view'));
        $this->assertNull($this->service->parseDriveUrl(''));
        $this->assertNull($this->service->parseDriveUrl('https://drive.google.com/'));
    }

    // ── Validation ──

    public function testValidateResourceInputRequiresTypeSpecificFields(): void
    {
        $errors = \TrainingResourceService::validateResourceInput([
            'class_id' => '',
            'type' => 'bogus',
            'title' => '',
        ]);
        $this->assertArrayHasKey('class_id', $errors);
        $this->assertArrayHasKey('type', $errors);
        $this->assertArrayHasKey('title', $errors);
    }

    public function testValidateResourceInputRecordingNeedsYouTube(): void
    {
        $errors = \TrainingResourceService::validateResourceInput([
            'class_id' => 'x',
            'type' => 'recording',
            'title' => 'T',
        ]);
        $this->assertArrayHasKey('youtube_url', $errors);
    }

    public function testValidateResourceInputAcceptsAnyHttpsUrlForDocsAndCode(): void
    {
        // Any well-formed https URL is valid for slides/pdf/code — no Drive
        // restriction anymore (issue: "remove the validation for pdf/ppt url").
        foreach (['slides', 'pdf', 'code'] as $type) {
            $errors = \TrainingResourceService::validateResourceInput([
                'class_id' => 'x',
                'type' => $type,
                'title' => 'T',
                'resource_url' => 'https://example.org/materials/session-1.pdf',
            ]);
            $this->assertSame([], $errors, "$type with a generic https URL must validate");
        }
    }

    public function testValidateResourceInputRejectsNonHttpsForDocsAndCode(): void
    {
        foreach (['http://example.org/a.pdf', 'ftp://example.org/a.pdf', 'javascript:alert(1)'] as $bad) {
            $errors = \TrainingResourceService::validateResourceInput([
                'class_id' => 'x',
                'type' => 'pdf',
                'title' => 'T',
                'resource_url' => $bad,
            ]);
            $this->assertArrayHasKey('resource_url', $errors, "$bad must be rejected");
        }
    }

    // ── CRUD + hydration ──

    private function makePaidClass(bool $open = true): array
    {
        return (new \ClassManagementService())->create([
            'title'            => 'Resource Test Class ' . uniqid(),
            'topic'            => 'Testing',
            'trainer_name'     => 'Trainer',
            'scheduled_at'     => '2026-01-10 10:00:00',
            'timezone'         => 'UTC',
            'is_paid'          => 1,
            'price'            => '999.00',
            'registration_open' => $open ? 1 : 0,
        ]);
    }

    public function testAddRecordingResourceStoresVideoIdAndComputedUrls(): void
    {
        $class = $this->makePaidClass();
        $res = $this->service->addResource([
            'class_id'    => $class['id'],
            'type'        => 'recording',
            'title'       => 'Session 1 Recording',
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);
        $this->assertSame('dQw4w9WgXcQ', $res['youtube_video_id']);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $res['watch_url']);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $res['embed_url']);
        $this->assertSame(1, $res['is_published']);
    }

    public function testAddDriveResourceStoresFileId(): void
    {
        $class = $this->makePaidClass();
        $res = $this->service->addResource([
            'class_id'       => $class['id'],
            'type'           => 'slides',
            'title'          => 'Session 1 Slides',
            'drive_url'      => 'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz1234567890/view',
            'file_name'      => 'slides.pdf',
            'file_size_label' => '2.4 MB',
        ]);
        $this->assertSame('1AbCdEfGhIjKlMnOpQrStUvWxYz1234567890', $res['drive_file_id']);
        $this->assertSame('slides.pdf', $res['file_name']);
        $this->assertStringContainsString('export=download&id=1AbCdEfGhIjKlMnOpQrStUvWxYz1234567890', $res['drive_download_url']);
    }

    public function testAddResourceRejectsInvalidYouTubeLinkForRecording(): void
    {
        $class = $this->makePaidClass();
        $this->expectException(\InvalidArgumentException::class);
        $this->service->addResource([
            'class_id'    => $class['id'],
            'type'        => 'recording',
            'title'       => 'Bad',
            'youtube_url' => 'https://vimeo.com/123',
        ]);
    }

    public function testAddResourceRejectsUnknownClass(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->service->addResource([
            'class_id'    => 'no-such-class',
            'type'        => 'recording',
            'title'       => 'Bad',
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);
    }

    public function testUpdateResourceChangesTitle(): void
    {
        $class = $this->makePaidClass();
        $res = $this->service->addResource([
            'class_id'    => $class['id'],
            'type'        => 'recording',
            'title'       => 'Old Title',
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);
        $updated = $this->service->updateResource($res['id'], ['title' => 'New Title']);
        $this->assertSame('New Title', $updated['title']);
        $this->assertSame('dQw4w9WgXcQ', $updated['youtube_video_id'], 'Unrelated fields must be preserved');
    }

    public function testSetPublishedTogglesVisibility(): void
    {
        $class = $this->makePaidClass();
        $res = $this->service->addResource([
            'class_id'    => $class['id'],
            'type'        => 'recording',
            'title'       => 'T',
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);
        $this->service->setPublished($res['id'], false);
        $this->assertSame(0, $this->service->getById($res['id'])['is_published']);
        $this->service->setPublished($res['id'], true);
        $this->assertSame(1, $this->service->getById($res['id'])['is_published']);
    }

    public function testDeleteResourceRemovesRow(): void
    {
        $class = $this->makePaidClass();
        $res = $this->service->addResource([
            'class_id'    => $class['id'],
            'type'        => 'recording',
            'title'       => 'T',
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);
        $this->service->deleteResource($res['id']);
        $this->assertNull($this->service->getById($res['id']));
    }

    public function testIncrementDownloadCount(): void
    {
        $class = $this->makePaidClass();
        $res = $this->service->addResource([
            'class_id' => $class['id'],
            'type'     => 'slides',
            'title'    => 'T',
            'drive_url' => 'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz1234567890/view',
        ]);
        $this->service->incrementDownloadCount($res['id']);
        $this->service->incrementDownloadCount($res['id']);
        $this->assertSame(2, $this->service->getById($res['id'])['download_count']);
    }

    // ── Generic URLs + GitHub code resources ──

    public function testParseGitHubUrlExtractsOwnerRepo(): void
    {
        $this->assertSame('octocat/hello-world', $this->service->parseGitHubUrl('https://github.com/octocat/hello-world'));
        $this->assertSame('octocat/hello-world', $this->service->parseGitHubUrl('https://github.com/octocat/hello-world/tree/main/src'));
        $this->assertSame('octocat/hello-world', $this->service->parseGitHubUrl('https://github.com/octocat/hello-world/blob/main/README.md'));
        $this->assertSame('octocat/hello-world', $this->service->parseGitHubUrl('https://www.github.com/octocat/hello-world/pull/12'));
    }

    public function testParseGitHubUrlRejectsNonGitHubAndIncomplete(): void
    {
        $this->assertNull($this->service->parseGitHubUrl('https://gitlab.com/octocat/hello-world'));
        $this->assertNull($this->service->parseGitHubUrl('https://github.com/'));
        $this->assertNull($this->service->parseGitHubUrl('https://github.com/only-owner'));
        $this->assertNull($this->service->parseGitHubUrl(''));
    }

    public function testAddPdfResourceWithGenericHttpsUrl(): void
    {
        $class = $this->makePaidClass();
        $res = $this->service->addResource([
            'class_id'     => $class['id'],
            'type'         => 'pdf',
            'title'        => 'Cheat Sheet',
            'resource_url' => 'https://cdn.example.org/materials/python-cheatsheet.pdf',
            'file_name'    => 'python-cheatsheet.pdf',
        ]);
        $this->assertSame('https://cdn.example.org/materials/python-cheatsheet.pdf', $res['resource_download_url']);
        $this->assertSame('cdn.example.org', $res['resource_host']);
        $this->assertNull($res['drive_file_id']);
    }

    public function testAddCodeResourceFromGitHubUrl(): void
    {
        $class = $this->makePaidClass();
        $res = $this->service->addResource([
            'class_id'     => $class['id'],
            'type'         => 'code',
            'title'        => 'Workshop Starter Repo',
            'resource_url' => 'https://github.com/codeandai/workshop-starter/tree/main',
        ]);
        $this->assertSame('https://github.com/codeandai/workshop-starter/tree/main', $res['resource_download_url']);
        $this->assertSame('codeandai/workshop-starter', $res['github_repo']);
    }

    public function testAddCodeResourceRejectsNonHttps(): void
    {
        $class = $this->makePaidClass();
        $this->expectException(\InvalidArgumentException::class);
        $this->service->addResource([
            'class_id'     => $class['id'],
            'type'         => 'code',
            'title'        => 'Bad',
            'resource_url' => 'http://github.com/codeandai/repo',
        ]);
    }

    public function testUpdateResourceCanSwapDriveUrlForGenericUrl(): void
    {
        $class = $this->makePaidClass();
        $res = $this->service->addResource([
            'class_id'  => $class['id'],
            'type'      => 'slides',
            'title'     => 'Deck',
            'drive_url' => 'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz1234567890/view',
        ]);
        $updated = $this->service->updateResource($res['id'], [
            'resource_url' => 'https://notion.site/codeandai/session-1-deck',
        ]);
        $this->assertSame('slides', $updated['type']);
        $this->assertSame('https://notion.site/codeandai/session-1-deck', $updated['resource_download_url']);
    }

    // ── Gating ──

    public function testFreeClassResourcesAreOpenToEveryone(): void
    {
        $freeId = \getDB()->query("SELECT id FROM demo_classes WHERE id = 'class-free'")->fetch()['id'] ?? 'class-free';
        if ($freeId !== 'class-free') {
            $this->markTestSkipped('Seeded free class missing');
        }
        $this->assertTrue($this->service->canDownload('class-free', null));
        $this->assertTrue($this->service->canDownload('class-free', 'anyone@example.com'));
    }

    public function testPaidClassBlocksNonAttendees(): void
    {
        $class = $this->makePaidClass();
        $this->assertFalse($this->service->canDownload($class['id'], null));
        $this->assertFalse($this->service->canDownload($class['id'], ''));
        $this->assertFalse($this->service->canDownload($class['id'], 'stranger@example.com'));
    }

    public function testPaidClassAllowsConfirmedAttendee(): void
    {
        $class = $this->makePaidClass();
        $registration = new \RegistrationService();
        $out = $registration->createRegistration('Attendee', 'student@example.com', '9999999999');
        \getDB()->exec(
            "UPDATE registrations SET registration_status = 'confirmed' WHERE id = '" . $out['registration']['id'] . "'"
        );

        // Case/whitespace-insensitive email matching, like the dashboard.
        $this->assertTrue($this->service->canDownload($class['id'], 'Student@Example.com '));
    }

    public function testPaidClassBlocksPendingAndCancelledRegistrations(): void
    {
        $class = $this->makePaidClass();
        $registration = new \RegistrationService();

        // Pending (not yet paid) — blocked.
        $out = $registration->createRegistration('Pending User', 'pending@example.com', '8888888888');
        $this->assertFalse($this->service->canDownload($class['id'], 'pending@example.com'));

        // Cancelled (payment failed) — blocked.
        $out = $registration->createRegistration('Cancelled User', 'cancelled@example.com', '7777777777');
        (new \UpiService())->failPaymentByOrderId($out['payment']['merchant_order_id']);
        $this->assertFalse($this->service->canDownload($class['id'], 'cancelled@example.com'));
    }

    // ── Public page grouping ──

    public function testPublishedGroupedByClassOnlyShowsPastClasses(): void
    {
        $past = $this->makePaidClass();
        $future = (new \ClassManagementService())->create([
            'title'        => 'Future Class ' . uniqid(),
            'topic'        => 'Testing',
            'trainer_name' => 'Trainer',
            'scheduled_at' => '2027-06-01 10:00:00',
            'timezone'     => 'UTC',
        ]);

        $this->service->addResource([
            'class_id'    => $past['id'],
            'type'        => 'recording',
            'title'       => 'Past Recording',
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);
        $this->service->addResource([
            'class_id'    => $future['id'],
            'type'        => 'recording',
            'title'       => 'Future Recording',
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);

        $groups = $this->service->getPublishedGroupedByClass();
        $ids = array_map(static fn ($g) => $g['class']['id'], $groups);
        $this->assertContains($past['id'], $ids);
        $this->assertNotContains($future['id'], $ids, 'Resources of future trainings must stay hidden');
    }

    public function testUnpublishedResourcesExcludedFromPublicGroups(): void
    {
        $past = $this->makePaidClass();
        $res = $this->service->addResource([
            'class_id'    => $past['id'],
            'type'        => 'recording',
            'title'       => 'Hidden Recording',
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);
        $this->service->setPublished($res['id'], false);

        $groups = $this->service->getPublishedGroupedByClass();
        foreach ($groups as $group) {
            foreach ($group['resources'] as $r) {
                $this->assertNotSame($res['id'], $r['id']);
            }
        }
    }

    public function testGetAllWithClassIncludesUnpublishedForOrganizer(): void
    {
        $past = $this->makePaidClass();
        $res = $this->service->addResource([
            'class_id'    => $past['id'],
            'type'        => 'recording',
            'title'       => 'Draft Recording',
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);
        $this->service->setPublished($res['id'], false);

        $all = $this->service->getAllWithClass();
        $ids = array_map(static fn ($r) => $r['id'], $all);
        $this->assertContains($res['id'], $ids, 'Organizer must see unpublished resources');
    }
}
