<?php

namespace Pterodactyl\Jobs\Schedule;

use Carbon\CarbonImmutable;
use Pterodactyl\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Pterodactyl\Services\Backups\InitiateBackupService;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonCommandRepository;
use Pterodactyl\Services\Schedules\HealthchecksPingService;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

class RunTaskJob implements ShouldQueue
{
    use Queueable;
    use DispatchesJobs;
    use SerializesModels;

    /**
     * RunTaskJob constructor.
     */
    public function __construct(public Task $task, public bool $manualRun = false)
    {
        $this->queue = 'standard';
    }

    /**
     * Run the job and send actions to the daemon running the server.
     *
     * @throws \Throwable
     */
    public function handle(
        DaemonCommandRepository $commandRepository,
        InitiateBackupService $backupService,
        DaemonPowerRepository $powerRepository,
    ) {
        // Do not process a task that is not set to active, unless it's been manually triggered.
        if (!$this->task->schedule->is_active && !$this->manualRun) {
            $this->skipped();

            return;
        }

        $server = $this->task->server;
        // If we made it to this point and the server status is not null it means the
        // server was likely suspended or marked as reinstalling after the schedule
        // was queued up. Just end the task right now — this should be a very rare
        // condition.
        if (!is_null($server->status)) {
            $this->failed();

            return;
        }

        // Ping the start of a run once, on the first task of the schedule. The job is
        // already executing at this point, so any time_offset delay on this task has
        // already elapsed.
        if ($this->task->sequence_id === $this->task->schedule->tasks->min('sequence_id')) {
            app(HealthchecksPingService::class)->ping($this->task->schedule, '/start');
        }

        $createdBackup = false;

        // Perform the provided task against the daemon.
        try {
            switch ($this->task->action) {
                case Task::ACTION_POWER:
                    $powerRepository->setServer($server)->send($this->task->payload);
                    break;
                case Task::ACTION_COMMAND:
                    $commandRepository->setServer($server)->send($this->task->payload);
                    break;
                case Task::ACTION_BACKUP:
                    $backupService->setSchedule($this->task->schedule)
                        ->setIgnoredFiles(explode(PHP_EOL, $this->task->payload))
                        ->handle($server, null, true);
                    $createdBackup = true;
                    break;
                default:
                    throw new \InvalidArgumentException('Invalid task action provided: ' . $this->task->action);
            }
        } catch (\Exception $exception) {
            // If this isn't a DaemonConnectionException on a task that allows for failures
            // throw the exception back up the chain so that the task is stopped.
            if (!($this->task->continue_on_failure && $exception instanceof DaemonConnectionException)) {
                throw $exception;
            }
        }

        $this->markTaskNotQueued();
        $this->queueNextTask($createdBackup);
    }

    /**
     * Handle a failure while sending the action to the daemon or otherwise processing the job.
     */
    public function failed(?\Exception $exception = null)
    {
        $this->skipped();

        app(HealthchecksPingService::class)->ping($this->task->schedule, '/fail');
    }

    /**
     * Marks the task as no longer queued and the schedule as complete without pinging
     * healthchecks.io. Used for runs that did not actually happen, such as an inactive
     * schedule being skipped, so that a false failure is not reported.
     */
    public function skipped(): void
    {
        $this->markTaskNotQueued();
        $this->markScheduleComplete();
    }

    /**
     * Get the next task in the schedule and queue it for running after the defined period of wait time.
     *
     * $createdBackup is true when this task created a backup row for the current run.
     * In that case the run's outcome is reported by the backup's completion callback
     * instead, not here, so no success ping is sent for it.
     */
    private function queueNextTask(bool $createdBackup)
    {
        /** @var Task|null $nextTask */
        $nextTask = Task::query()->where('schedule_id', $this->task->schedule_id)
            ->orderBy('sequence_id', 'asc')
            ->where('sequence_id', '>', $this->task->sequence_id)
            ->first();

        if (is_null($nextTask)) {
            $this->markScheduleComplete();

            if (!$createdBackup) {
                app(HealthchecksPingService::class)->ping($this->task->schedule);
            }

            return;
        }

        $nextTask->update(['is_queued' => true]);

        $this->dispatch((new self($nextTask, $this->manualRun))->delay($nextTask->time_offset));
    }

    /**
     * Marks the parent schedule as being complete.
     */
    private function markScheduleComplete()
    {
        $this->task->schedule()->update([
            'is_processing' => false,
            'last_run_at' => CarbonImmutable::now()->toDateTimeString(),
        ]);
    }

    /**
     * Mark a specific task as no longer being queued.
     */
    private function markTaskNotQueued()
    {
        $this->task->update(['is_queued' => false]);
    }
}
