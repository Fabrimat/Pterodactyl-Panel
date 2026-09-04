<?php

namespace Pterodactyl\Tests\Integration\Api\Client\Server\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Permission;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

class DownloadBackupTest extends ClientApiIntegrationTestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBackupDataProvider')]
    public function testBackupCannotBeDownloadedUntilSuccessfulAndComplete(bool $isSuccessful, bool $isCompleted)
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_BACKUP_DOWNLOAD]);

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'is_successful' => $isSuccessful,
            'completed_at' => $isCompleted ? CarbonImmutable::now() : null,
        ]);

        $this->actingAs($user)->getJson($this->link($backup, 'download'))
            ->assertStatus(Response::HTTP_BAD_REQUEST);
    }

    public static function invalidBackupDataProvider(): array
    {
        return [
            'failed completed' => [false, true],
            'failed incomplete' => [false, false],
            'successful incomplete' => [true, false],
        ];
    }

    public function testSuccessfulBackupCanBeDownloaded()
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_BACKUP_DOWNLOAD]);

        /** @var Backup $backup */
        $backup = Backup::factory()->create(['server_id' => $server->id]);

        $this->actingAs($user)->getJson($this->link($backup, 'download'))
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonStructure(['object', 'attributes' => ['url']]);
    }
}
