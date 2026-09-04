<?php

namespace Pterodactyl\Tests\Integration\Api\Remote;

use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Schedule;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

class BackupStatusControllerTest extends IntegrationTestCase
{
    /**
     * Test that a successful completion pings the bare healthchecks.io check URL,
     * after the update transaction has finished, when the backup is stamped with a
     * schedule that has a check configured.
     */
    public function testSuccessfulCompletionPingsHealthchecksWhenBackupHasASchedule(): void
    {
        config(['healthchecks.url' => 'https://hc-ping.test']);

        Http::preventStrayRequests();
        Http::fake([
            'hc-ping.test/*' => Http::response('OK'),
        ]);

        $server = $this->createServerModel();
        $node = $server->node;

        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create([
            'server_id' => $server->id,
            'healthchecks_uuid' => '9d3b7e2a-6c1f-4b2e-9c3d-1a2b3c4d5e6f',
        ]);

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'schedule_id' => $schedule->id,
            'is_successful' => false,
            'completed_at' => null,
        ]);

        $this->withHeader('Authorization', "Bearer $node->daemon_token_id." . $node->getDecryptedKey())
            ->postJson("/api/remote/backups/{$backup->uuid}", [
                'successful' => true,
                'checksum_type' => 'sha1',
                'checksum' => str_repeat('a', 40),
                'size' => 1024,
            ])
            ->assertNoContent();

        Http::assertSent(function ($request) use ($schedule) {
            return $request->url() === 'https://hc-ping.test/' . $schedule->healthchecks_uuid;
        });

        Http::assertSentCount(1);
    }

    /**
     * Test that a failed completion pings the '/fail' healthchecks.io endpoint under
     * the same conditions.
     */
    public function testFailedCompletionPingsTheFailEndpointWhenBackupHasASchedule(): void
    {
        config(['healthchecks.url' => 'https://hc-ping.test']);

        Http::preventStrayRequests();
        Http::fake([
            'hc-ping.test/*' => Http::response('OK'),
        ]);

        $server = $this->createServerModel();
        $node = $server->node;

        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create([
            'server_id' => $server->id,
            'healthchecks_uuid' => '9d3b7e2a-6c1f-4b2e-9c3d-1a2b3c4d5e6f',
        ]);

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'schedule_id' => $schedule->id,
            'is_successful' => false,
            'completed_at' => null,
        ]);

        $this->withHeader('Authorization', "Bearer $node->daemon_token_id." . $node->getDecryptedKey())
            ->postJson("/api/remote/backups/{$backup->uuid}", [
                'successful' => false,
            ])
            ->assertNoContent();

        Http::assertSent(function ($request) use ($schedule) {
            return $request->url() === 'https://hc-ping.test/' . $schedule->healthchecks_uuid . '/fail';
        });

        Http::assertSentCount(1);
    }

    /**
     * Test that a completion for a backup with no schedule pings nothing at all,
     * since there is no check to report to.
     */
    public function testCompletionPingsNothingWhenBackupHasNoSchedule(): void
    {
        config(['healthchecks.url' => 'https://hc-ping.test']);

        Http::preventStrayRequests();
        Http::fake();

        $server = $this->createServerModel();
        $node = $server->node;

        /** @var Backup $backup */
        $backup = Backup::factory()->create([
            'server_id' => $server->id,
            'is_successful' => false,
            'completed_at' => null,
        ]);

        $this->withHeader('Authorization', "Bearer $node->daemon_token_id." . $node->getDecryptedKey())
            ->postJson("/api/remote/backups/{$backup->uuid}", [
                'successful' => true,
                'checksum_type' => 'sha1',
                'checksum' => str_repeat('a', 40),
                'size' => 1024,
            ])
            ->assertNoContent();

        Http::assertNothingSent();
    }
}
