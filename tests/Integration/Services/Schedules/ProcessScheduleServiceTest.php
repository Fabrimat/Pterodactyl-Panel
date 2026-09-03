<?php

namespace Pterodactyl\Tests\Integration\Services\Schedules;

use Exception;
use Carbon\CarbonImmutable;
use Pterodactyl\Models\Task;
use Pterodactyl\Models\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Contracts\Bus\Dispatcher;
use Pterodactyl\Jobs\Schedule\RunTaskJob;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Services\Schedules\ProcessScheduleService;

class ProcessScheduleServiceTest extends IntegrationTestCase
{
    /**
     * Test that a schedule with no tasks registered returns an error.
     */
    public function testScheduleWithNoTasksReturnsException()
    {
        $server = $this->createServerModel();
        $schedule = Schedule::factory()->create(['server_id' => $server->id]);

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('Cannot process schedule for task execution: no tasks are registered.');

        $this->getService()->handle($schedule);
    }

    /**
     * Test that an error during the schedule update is not persisted to the database.
     */
    public function testErrorDuringScheduleDataUpdateDoesNotPersistChanges()
    {
        $server = $this->createServerModel();

        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create([
            'server_id' => $server->id,
            'cron_minute' => 'hodor', // this will break the getNextRunDate() function.
        ]);

        /** @var Task $task */
        $task = Task::factory()->create(['schedule_id' => $schedule->id, 'sequence_id' => 1]);

        $this->expectException(\InvalidArgumentException::class);

        $this->getService()->handle($schedule);

        $this->assertDatabaseMissing('schedules', ['id' => $schedule->id, 'is_processing' => true]);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id, 'is_queued' => true]);
    }

    /**
     * Test that a job is dispatched as expected using the initial delay.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('dispatchNowDataProvider')]
    public function testJobCanBeDispatchedWithExpectedInitialDelay(bool $now)
    {
        Bus::fake();

        $server = $this->createServerModel();

        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create(['server_id' => $server->id]);

        /** @var Task $task */
        $task = Task::factory()->create(['schedule_id' => $schedule->id, 'time_offset' => 10, 'sequence_id' => 1]);

        $this->getService()->handle($schedule, $now);

        Bus::assertDispatched(RunTaskJob::class, function ($job) use ($now, $task) {
            $this->assertInstanceOf(RunTaskJob::class, $job);
            $this->assertSame($task->id, $job->task->id);
            // Jobs using dispatchNow should not have a delay associated with them.
            $this->assertSame($now ? null : 10, $job->delay);

            return true;
        });

        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'is_processing' => true]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'is_queued' => true]);
    }

    /**
     * Test that even if a schedule's task sequence gets messed up the first task based on
     * the ascending order of tasks is used.
     *
     * @see https://github.com/pterodactyl/panel/issues/2534
     */
    public function testFirstSequenceTaskIsFound()
    {
        Bus::fake();

        $server = $this->createServerModel();
        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create(['server_id' => $server->id]);

        /** @var Task $task */
        $task2 = Task::factory()->create(['schedule_id' => $schedule->id, 'sequence_id' => 4]);
        $task = Task::factory()->create(['schedule_id' => $schedule->id, 'sequence_id' => 2]);
        $task3 = Task::factory()->create(['schedule_id' => $schedule->id, 'sequence_id' => 3]);

        $this->getService()->handle($schedule);

        Bus::assertDispatched(RunTaskJob::class, function (RunTaskJob $job) use ($task) {
            return $task->id === $job->task->id;
        });

        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'is_processing' => true]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'is_queued' => true]);
        $this->assertDatabaseHas('tasks', ['id' => $task2->id, 'is_queued' => false]);
        $this->assertDatabaseHas('tasks', ['id' => $task3->id, 'is_queued' => false]);
    }

    /**
     * Tests that a task's processing state is reset correctly if using "dispatchNow" and there is
     * an exception encountered while running it.
     *
     * @see https://github.com/pterodactyl/panel/issues/2550
     */
    public function testTaskDispatchedNowIsResetProperlyIfErrorIsEncountered()
    {
        $this->swap(Dispatcher::class, $dispatcher = \Mockery::mock(Dispatcher::class));

        $server = $this->createServerModel();
        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create(['server_id' => $server->id, 'last_run_at' => null]);
        /** @var Task $task */
        $task = Task::factory()->create(['schedule_id' => $schedule->id, 'sequence_id' => 1]);

        $dispatcher->expects('dispatchNow')->andThrows(new \Exception('Test thrown exception'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Test thrown exception');

        $this->getService()->handle($schedule, true);

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'is_processing' => false,
            'last_run_at' => CarbonImmutable::now()->toAtomString(),
        ]);

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'is_queued' => false]);
    }

    /**
     * Test that the "/fail" healthchecks.io endpoint is pinged when a manual "dispatchNow"
     * run encounters an error.
     *
     * @see https://github.com/pterodactyl/panel/issues/2550
     */
    public function testHealthchecksFailPingIsSentWhenDispatchNowEncountersError()
    {
        config(['healthchecks.url' => 'https://hc-ping.test']);

        Http::preventStrayRequests();
        Http::fake([
            'hc-ping.test/*' => Http::response('OK'),
        ]);

        $this->swap(Dispatcher::class, $dispatcher = \Mockery::mock(Dispatcher::class));

        $server = $this->createServerModel();
        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create([
            'server_id' => $server->id,
            'last_run_at' => null,
            'healthchecks_uuid' => '9d3b7e2a-6c1f-4b2e-9c3d-1a2b3c4d5e6f',
        ]);
        /** @var Task $task */
        $task = Task::factory()->create(['schedule_id' => $schedule->id, 'sequence_id' => 1]);

        $dispatcher->expects('dispatchNow')->andThrows(new \Exception('Test thrown exception'));

        try {
            $this->getService()->handle($schedule, true);
            $this->fail('Expected exception was not thrown by handle().');
        } catch (\Exception $exception) {
            $this->assertSame('Test thrown exception', $exception->getMessage());
        }

        Http::assertSent(function ($request) use ($schedule) {
            return $request->url() === 'https://hc-ping.test/' . $schedule->healthchecks_uuid . '/fail';
        });

        // This path calls failed() and then skipped(), so the count is what proves
        // only the first of the two reports an outcome.
        Http::assertSentCount(1);
    }

    /**
     * Test that a schedule using "only_when_online" does not ping healthchecks.io at all
     * when the server is offline, since the task never actually ran.
     */
    public function testOnlyWhenOnlineOfflineServerDoesNotPingHealthchecks()
    {
        config(['healthchecks.url' => 'https://hc-ping.test']);

        Bus::fake();
        Http::preventStrayRequests();
        Http::fake();

        $server = $this->createServerModel();
        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create([
            'server_id' => $server->id,
            'only_when_online' => true,
            'healthchecks_uuid' => '9d3b7e2a-6c1f-4b2e-9c3d-1a2b3c4d5e6f',
        ]);
        /** @var Task $task */
        $task = Task::factory()->create(['schedule_id' => $schedule->id, 'sequence_id' => 1]);

        $mock = \Mockery::mock(DaemonServerRepository::class);
        $this->instance(DaemonServerRepository::class, $mock);
        $mock->expects('setServer')->andReturnSelf();
        $mock->expects('getDetails')->andReturn(['state' => 'offline']);

        $this->getService()->handle($schedule);

        Http::assertNothingSent();
    }

    public static function dispatchNowDataProvider(): array
    {
        return [[true], [false]];
    }

    private function getService(): ProcessScheduleService
    {
        return $this->app->make(ProcessScheduleService::class);
    }
}
