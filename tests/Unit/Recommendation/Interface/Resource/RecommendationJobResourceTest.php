<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recommendation\Interface\Resource;

use App\Recommendation\Domain\Model\RecommendationJob;
use App\Recommendation\Interface\Resource\RecommendationJobResource;
use PHPUnit\Framework\TestCase;

final class RecommendationJobResourceTest extends TestCase
{
    private const string ADMIN_ID = '0192f3c4-0000-7000-8000-00000000000a';

    private const string ADMIN_PUBLIC_ID = 'adminPublicId00000001';

    private const string DELETED_USER_ID = '0192f3c4-0000-7000-8000-00000000000b';

    public function testAStoredUserIdShowsAsThatUsersPublicIdAndOtherActorsAsStored(): void
    {
        $jobs = [
            RecommendationJob::create(isFull: false, metadata: ['triggered_by' => self::ADMIN_ID]),
            RecommendationJob::create(isFull: false, metadata: ['triggered_by' => 'system', 'requeued_by' => self::ADMIN_ID]),
            RecommendationJob::create(isFull: false, metadata: ['triggered_by' => 'cli', 'requeued_by' => 'cli']),
            RecommendationJob::create(isFull: false, metadata: ['triggered_by' => self::DELETED_USER_ID]),
        ];
        $lookups = [];
        $publicUserId = static function (string $userId) use (&$lookups): ?string {
            $lookups[] = $userId;

            return $userId === self::ADMIN_ID ? self::ADMIN_PUBLIC_ID : null;
        };

        $shown = RecommendationJobResource::collectionWithActors($jobs, RecommendationJobResource::actorPublicIds($jobs, $publicUserId));

        self::assertSame([self::ADMIN_ID, 'system', 'cli', self::DELETED_USER_ID], $lookups);
        self::assertSame(['triggered_by' => self::ADMIN_PUBLIC_ID], $shown[0]['metadata']);
        self::assertSame(['triggered_by' => 'system', 'requeued_by' => self::ADMIN_PUBLIC_ID], $shown[1]['metadata']);
        self::assertSame(['triggered_by' => 'cli', 'requeued_by' => 'cli'], $shown[2]['metadata']);
        self::assertSame(['triggered_by' => '[internal ID]'], $shown[3]['metadata']);
        self::assertSame($shown[1], RecommendationJobResource::from($jobs[1], [self::ADMIN_ID => self::ADMIN_PUBLIC_ID]));
    }
}
