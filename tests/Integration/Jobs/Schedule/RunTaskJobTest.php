<?php

namespace Pterodactyl\Tests\Integration\Jobs\Schedule;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Request;
use Pterodactyl\Models\Task;
use GuzzleHttp\Psr7\Response;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Jobs\Schedule\RunTaskJob;
use GuzzleHttp\Exception\BadResponseException;
use Illuminate\Http\Client\ConnectionException;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonBackupRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

class RunTaskJobTest extends IntegrationTestCase
{
    /**
     * An inactive job should not be run by the system.
     */
    public function testInactiveJobIsNotRun()
    {
        $server = $this->createServerModel();

        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create([
            'server_id' => $server->id,
            'is_processing' => true,
            'last_run_at' => null,
            'is_active' => false,
        ]);
        /** @var Task $task */
        $task = Task::factory()->create(['schedule_id' => $schedule->id, 'is_queued' => true]);

        $job = new RunTaskJob($task);

        Bus::dispatchSync($job);

        $task->refresh();
        $schedule->refresh();

        $this->assertFalse($task->is_queued);
        $this->assertFalse($schedule->is_processing);
        $this->assertFalse($schedule->is_active);
        $this->assertTrue(CarbonImmutable::now()->isSameAs(\DateTimeInterface::ATOM, $schedule->last_run_at));
    }

    /**
     * An inactive job should not ping healthchecks.io in either direction, since the task
     * never actually ran.
     */
    public function testInactiveJobDoesNotPingHealthchecks()
    {
        config(['healthchecks.url' => 'https://hc-ping.test']);

        Http::preventStrayRequests();
        Http::fake();

        $server = $this->createServerModel();

        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create([
            'server_id' => $server->id,
            'is_processing' => true,
            'last_run_at' => null,
            'is_active' => false,
            'healthchecks_uuid' => '9d3b7e2a-6c1f-4b2e-9c3d-1a2b3c4d5e6f',
        ]);
        /** @var Task $task */
        $task = Task::factory()->create(['schedule_id' => $schedule->id, 'is_queued' => true]);

        Bus::dispatchSync(new RunTaskJob($task));

        Http::assertNothingSent();
    }

    public function testJobWithInvalidActionThrowsException()
    {
        $server = $this->createServerModel();

        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create(['server_id' => $server->id]);
        /** @var Task $task */
        $task = Task::factory()->create(['schedule_id' => $schedule->id, 'action' => 'foobar']);

        $job = new RunTaskJob($task);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid task action provided: foobar');
        Bus::dispatchSync($job);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('isManualRunDataProvider')]
    public function testJobIsExecuted(bool $isManualRun)
    {
        $server = $this->createServerModel();

        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create([
            'server_id' => $server->id,
            'is_active' => !$isManualRun,
            'is_processing' => true,
            'last_run_at' => null,
        ]);
        /** @var Task $task */
        $task = Task::factory()->create([
            'schedule_id' => $schedule->id,
            'action' => Task::ACTION_POWER,
            'payload' => 'start',
            'is_queued' => true,
            'continue_on_failure' => false,
        ]);

        $mock = \Mockery::mock(DaemonPowerRepository::class);
        $this->instance(DaemonPowerRepository::class, $mock);

        $mock->expects('setServer')->with(\Mockery::on(function ($value) use ($server) {
            return $value instanceof Server && $value->id === $server->id;
        }))->andReturnSelf();
        $mock->expects('send')->with('start')->andReturn(new Response());

        Bus::dispatchSync(new RunTaskJob($task, $isManualRun));

        $task->refresh();
        $schedule->refresh();

        $this->assertFalse($task->is_queued);
        $this->assertFalse($schedule->is_processing);
        $this->assertTrue(CarbonImmutable::now()->isSameAs(\DateTimeInterface::ATOM, $schedule->last_run_at));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('isManualRunDataProvider')]
    public function testExceptionDuringRunIsHandledCorrectly(bool $continueOnFailure)
    {
        $server = $this->createServerModel();

        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create(['server_id' => $server->id]);
        /** @var Task $task */
        $task = Task::factory()->create([
            'schedule_id' => $schedule->id,
            'action' => Task::ACTION_POWER,
            'payload' => 'start',
            'continue_on_failure' => $continueOnFailure,
        ]);

        $mock = \Mockery::mock(DaemonPowerRepository::class);
        $this->instance(DaemonPowerRepository::class, $mock);

        $mock->expects('setServer->send')->andThrow(
            new DaemonConnectionException(new BadResponseException('Bad request', new Request('GET', '/test'), new Response()))
        );

        if (!$continueOnFailure) {
            $this->expectException(DaemonConnectionException::class);
        }

        Bus::dispatchSync(new RunTaskJob($task));

        if ($continueOnFailure) {
            $task->refresh();
            $schedule->refresh();

            $this->assertFalse($task->is_queued);
            $this->assertFalse($schedule->is_processing);
            $this->assertTrue(CarbonImmutable::now()->isSameAs(\DateTimeInterface::ATOM, $schedule->last_run_at));
        }
    }

    /**
     * Test that a schedule is not executed if the server is suspended.
     *
     * @see https://github.com/pterodactyl/panel/issues/4008
     */
    public function testTaskIsNotRunIfServerIsSuspended()
    {
        $server = $this->createServerModel([
            'status' => Server::STATUS_SUSPENDED,
        ]);

        $schedule = Schedule::factory()->for($server)->create([
            'last_run_at' => Carbon::now()->subHour(),
        ]);

        $task = Task::factory()->for($schedule)->create([
            'action' => Task::ACTION_POWER,
            'payload' => 'start',
        ]);

        Bus::dispatchSync(new RunTaskJob($task));

        $task->refresh();
        $schedule->refresh();

        $this->assertFalse($task->is_queued);
        $this->assertFalse($schedule->is_processing);
        $this->assertTrue(Carbon::now()->isSameAs(\DateTimeInterface::ATOM, $schedule->last_run_at));
    }

    /**
     * Test that a run pings healthchecks.io twice: once to '/start' when the first
     * task begins, and again to the bare check URL once the last task in the
     * schedule completes successfully, in that order.
     */
    public function testHealthchecksIsPingedOnSuccessfulCompletion()
    {
        config(['healthchecks.url' => 'https://hc-ping.test']);

        Http::preventStrayRequests();
        Http::fake([
            'hc-ping.test/*' => Http::response('OK'),
        ]);

        $server = $this->createServerModel();

        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create([
            'server_id' => $server->id,
            'is_processing' => true,
            'last_run_at' => null,
            'healthchecks_uuid' => '9d3b7e2a-6c1f-4b2e-9c3d-1a2b3c4d5e6f',
        ]);
        /** @var Task $task */
        $task = Task::factory()->create([
            'schedule_id' => $schedule->id,
            'action' => Task::ACTION_POWER,
            'payload' => 'start',
            'is_queued' => true,
        ]);

        $mock = \Mockery::mock(DaemonPowerRepository::class);
        $this->instance(DaemonPowerRepository::class, $mock);
        $mock->expects('setServer')->andReturnSelf();
        $mock->expects('send')->with('start')->andReturn(new Response());

        Bus::dispatchSync(new RunTaskJob($task));

        $recorded = Http::recorded();

        // A run that reported both a success and a failure, or reported the same
        // outcome twice, would still satisfy assertSent on its own, so the count and
        // the order of the two requests both have to be checked here.
        $this->assertCount(2, $recorded);
        $this->assertSame('https://hc-ping.test/' . $schedule->healthchecks_uuid . '/start', $recorded[0][0]->url());
        $this->assertSame('https://hc-ping.test/' . $schedule->healthchecks_uuid, $recorded[1][0]->url());
    }

    /**
     * Test that a schedule whose last task is a backup pings the start check but not
     * the bare success URL when the run ends. InitiateBackupService::handle() returns
     * as soon as Wings accepts the backup request; the archive itself is produced
     * asynchronously on the node, so only the backup's own completion callback is
     * allowed to report the run's outcome.
     */
    public function testHealthchecksStartPingsButNoSuccessPingWhenLastTaskIsABackup()
    {
        config(['healthchecks.url' => 'https://hc-ping.test']);

        Http::preventStrayRequests();
        Http::fake([
            'hc-ping.test/*' => Http::response('OK'),
        ]);

        $server = $this->createServerModel(['backup_limit' => 1]);

        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create([
            'server_id' => $server->id,
            'is_processing' => true,
            'last_run_at' => null,
            'healthchecks_uuid' => '9d3b7e2a-6c1f-4b2e-9c3d-1a2b3c4d5e6f',
        ]);
        /** @var Task $task */
        $task = Task::factory()->create([
            'schedule_id' => $schedule->id,
            'action' => Task::ACTION_BACKUP,
            'payload' => '',
            'sequence_id' => 1,
            'is_queued' => true,
        ]);

        $mock = $this->mock(DaemonBackupRepository::class);
        $mock->expects('setServer->setBackupAdapter->backup')->andReturn(new Response());

        Bus::dispatchSync(new RunTaskJob($task));

        Http::assertSent(function ($request) use ($schedule) {
            return $request->url() === 'https://hc-ping.test/' . $schedule->healthchecks_uuid . '/start';
        });

        Http::assertSentCount(1);

        // Deferring the success ping is only safe because the backup row carries the
        // schedule that is owed that ping. Without this the whole feature can be
        // removed without a single test failing, and every scheduled backup then
        // reports nothing at all: the job defers, and the completion endpoint finds
        // no schedule to ping for.
        $this->assertDatabaseHas('backups', ['server_id' => $server->id, 'schedule_id' => $schedule->id]);
    }

    /**
     * Test that a healthchecks.io ping that fails with a connection exception does not
     * change the way the schedule and task end up in the database.
     */
    public function testHealthchecksPingFailureDoesNotAffectScheduleState()
    {
        config(['healthchecks.url' => 'https://hc-ping.test']);

        Http::preventStrayRequests();
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $server = $this->createServerModel();

        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create([
            'server_id' => $server->id,
            'is_processing' => true,
            'last_run_at' => null,
            'healthchecks_uuid' => '9d3b7e2a-6c1f-4b2e-9c3d-1a2b3c4d5e6f',
        ]);
        /** @var Task $task */
        $task = Task::factory()->create([
            'schedule_id' => $schedule->id,
            'action' => Task::ACTION_POWER,
            'payload' => 'start',
            'is_queued' => true,
        ]);

        $mock = \Mockery::mock(DaemonPowerRepository::class);
        $this->instance(DaemonPowerRepository::class, $mock);
        $mock->expects('setServer')->andReturnSelf();
        $mock->expects('send')->with('start')->andReturn(new Response());

        Bus::dispatchSync(new RunTaskJob($task));

        $task->refresh();
        $schedule->refresh();

        $this->assertFalse($task->is_queued);
        $this->assertFalse($schedule->is_processing);
        $this->assertTrue(CarbonImmutable::now()->isSameAs(\DateTimeInterface::ATOM, $schedule->last_run_at));
    }

    public static function isManualRunDataProvider(): array
    {
        return [[true], [false]];
    }
}
