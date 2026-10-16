<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\CreateCommitImpressions;
use App\Models\Commit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

final readonly class ConcurrentImpressionBatches
{
    /**
     * Each child gets its own PostgreSQL connection. The parent holds the commit locks until every child is waiting
     * on a database lock, so these tests exercise actual contention rather than merely starting two quick processes.
     *
     * @param  list<array{user: User, batch_id: string, impressions: list<array{commit_id: string, token: string}>}>  $batches
     */
    public function run(array $batches): void
    {
        DB::disconnect();
        $children = [];

        try {
            foreach ($batches as $batch) {
                $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

                throw_if($sockets === false, RuntimeException::class, 'Could not create the concurrency test sockets.');

                $pid = pcntl_fork();

                throw_if($pid === -1, RuntimeException::class, 'Could not fork the concurrency test worker.');

                if ($pid === 0) {
                    fclose($sockets[0]);
                    $this->work($sockets[1], $batch);
                }

                fclose($sockets[1]);
                stream_set_timeout($sockets[0], 20);
                $children[] = ['pid' => $pid, 'socket' => $sockets[0]];
            }

            $backendPids = [];

            foreach ($children as $child) {
                $ready = fgets($child['socket']);

                throw_if($ready === false || ! ctype_digit(mb_trim($ready)), RuntimeException::class, 'Concurrency worker did not open its database connection: '.($ready ?: 'no response'));

                $backendPids[] = (int) mb_trim($ready);
            }

            DB::beginTransaction();
            Commit::query()->whereKey(array_column(array_merge(...array_column($batches, 'impressions')), 'commit_id'))->orderBy('id')->lockForUpdate()->get(['id']);

            foreach ($children as $child) {
                fwrite($child['socket'], "start\n");
            }

            $deadline = microtime(true) + 5;

            do {
                DB::statement('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->whereIn('pid', $backendPids)->where('wait_event_type', 'Lock')->count();

                if ($waiting === count($children)) {
                    break;
                }

                Sleep::usleep(10_000);
            } while (microtime(true) < $deadline);

            throw_if($waiting !== count($children), RuntimeException::class, 'The concurrency workers did not all reach database contention.');

            DB::commit();

            foreach ($children as $index => $child) {
                $result = fgets($child['socket']);
                fclose($child['socket']);
                pcntl_waitpid($child['pid'], $status);
                unset($children[$index]);

                throw_if($result !== "ok\n" || ! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0, RuntimeException::class, 'Concurrent impression worker failed: '.($result ?: 'no response'));
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($children as $child) {
                posix_kill($child['pid'], SIGKILL);
                pcntl_waitpid($child['pid'], $status);
                fclose($child['socket']);
            }
        }
    }

    /**
     * @param  resource  $socket
     * @param  array{user: User, batch_id: string, impressions: list<array{commit_id: string, token: string}>}  $batch
     */
    private function work(mixed $socket, array $batch): never
    {
        try {
            DB::purge();
            DB::statement("SET statement_timeout = '15s'");
            $pid = DB::selectOne('SELECT pg_backend_pid() AS pid');
            fwrite($socket, $pid->pid."\n");

            throw_if(fgets($socket) !== "start\n", RuntimeException::class, 'The concurrency worker did not receive its start signal.');

            resolve(CreateCommitImpressions::class)->handle($batch['user'], $batch['batch_id'], $batch['impressions']);
            fwrite($socket, "ok\n");
            fclose($socket);
            DB::disconnect();

            exit(0);
        } catch (Throwable $throwable) {
            fwrite($socket, $throwable::class.': '.$throwable->getMessage()."\n");
            fclose($socket);

            exit(1);
        }
    }
}
