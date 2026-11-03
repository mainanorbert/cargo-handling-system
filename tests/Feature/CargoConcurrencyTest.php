<?php

namespace Tests\Feature;

use App\Exceptions\CargoException;
use App\Models\Commodity;
use App\Models\Shipment;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\Vessel;
use App\Services\CargoService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CargoConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        if (($_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION')) !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires a dedicated PostgreSQL test database and pcntl.');
        }

        parent::setUp();
    }

    /** @return array{Shipment, Tank} */
    private function cargo(): array
    {
        $commodity = Commodity::factory()->create(['code' => 'CPO', 'description' => 'Crude Palm Oil']);
        $vessel = Vessel::factory()->create(['name' => 'Vessel']);
        $shipment = Shipment::factory()->create(['reference' => 'S1', 'origin' => 'Origin', 'vessel_id' => $vessel->id, 'commodity_id' => $commodity->id, 'expected_ml' => 100000000]);
        $tank = Tank::factory()->create(['name' => 'Tank A', 'capacity_ml' => 99500000]);

        return [$shipment, $tank];
    }

    /** @return array<string, mixed> */
    private function receipt(Shipment $shipment, Tank $tank, string $reference): array
    {
        return ['type' => 'inbound', 'event_reference' => $reference, 'shipment_id' => $shipment->id, 'tank_id' => $tank->id, 'quantity_litres' => '100000', 'occurred_at' => '2026-01-01 10:00:00'];
    }

    public function test_competing_receipts_cannot_overallocate_capacity(): void
    {
        [$shipment, $tank] = $this->cargo();

        $results = $this->compete([
            $this->receipt($shipment, $tank, 'A'),
            $this->receipt($shipment, $tank, 'B'),
        ]);

        sort($results);
        $this->assertSame(['App\\Exceptions\\TankCapacityException', 'applied'], $results);
        $this->assertSame(99500000, $tank->fresh()->balance_ml);
        $this->assertDatabaseCount('movements', 1);
    }

    public function test_duplicate_events_on_different_tanks_are_applied_only_once(): void
    {
        [$shipment, $tank] = $this->cargo();
        $otherTank = Tank::factory()->create(['name' => 'Tank B', 'capacity_ml' => 99500000]);

        $results = $this->compete([
            $this->receipt($shipment, $tank, 'DUPLICATE'),
            $this->receipt($shipment, $otherTank, 'DUPLICATE'),
        ]);

        sort($results);
        $this->assertSame(['App\\Exceptions\\CargoException', 'applied'], $results);
        $this->assertSame(99500000, (int) Tank::sum('balance_ml'));
        $this->assertDatabaseCount('movements', 1);
    }

    public function test_competing_deliveries_cannot_allocate_the_same_stock(): void
    {
        [$shipment, $tank] = $this->cargo();
        $truck = Truck::factory()->create(['registration' => 'TRUCK']);
        app(CargoService::class)->record($this->receipt($shipment, $tank, 'RECEIPT'));
        $delivery = ['type' => 'outbound', 'truck_id' => $truck->id, 'tank_id' => $tank->id, 'quantity_litres' => '60000', 'occurred_at' => '2026-01-01 11:00:00'];

        $results = $this->compete([
            [...$delivery, 'event_reference' => 'DELIVERY-A'],
            [...$delivery, 'event_reference' => 'DELIVERY-B'],
        ]);

        sort($results);
        $this->assertSame(['App\\Exceptions\\CargoException', 'applied'], $results);
        $this->assertSame(39500000, $tank->fresh()->balance_ml);
        $this->assertDatabaseCount('movements', 2);
    }

    /**
     * Hold both workers at real row locks before allowing them to compete.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<string>
     */
    private function compete(array $events): array
    {
        $directory = sys_get_temp_dir().'/cargo-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $prefix = 'cargo_race_'.bin2hex(random_bytes(6));
        $children = [];
        DB::disconnect();

        try {
            foreach ($events as $index => $event) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('Could not fork the competing worker.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        DB::select("select set_config('application_name', ?, false)", [$prefix.'_'.$index]);
                        touch($directory.'/ready-'.$index);
                        $deadline = microtime(true) + 15;
                        while (! file_exists($directory.'/start')) {
                            if (microtime(true) >= $deadline) {
                                throw new \RuntimeException('Timed out waiting for test barrier.');
                            }
                            usleep(10000);
                        }
                        app(CargoService::class)->record($event);
                        $result = 'applied';
                    } catch (CargoException $exception) {
                        $result = $exception::class;
                    } catch (\Throwable $exception) {
                        $result = $exception::class.': '.$exception->getMessage();
                    }
                    file_put_contents($directory.'/result-'.$index, $result);
                    DB::disconnect();
                    exit(0);
                }
                $children[] = $pid;
            }

            $this->waitUntil(fn (): bool => count(glob($directory.'/ready-*')) === count($events));
            DB::beginTransaction();
            Tank::whereIn('id', array_column($events, 'tank_id'))->orderBy('id')->lockForUpdate()->get();
            touch($directory.'/start');
            $this->waitUntil(function () use ($prefix, $events): bool {
                $waiting = DB::selectOne("select count(*) as total from pg_stat_activity where application_name like ? and wait_event_type = 'Lock'", [$prefix.'%']);

                return (int) $waiting->total === count($events);
            });
            DB::commit();
            $this->waitUntil(fn (): bool => count(glob($directory.'/result-*')) === count($events));

            return array_map(fn (int $index): string => file_get_contents($directory.'/result-'.$index), array_keys($events));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($children as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    posix_kill($pid, SIGTERM);
                    pcntl_waitpid($pid, $status);
                }
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function waitUntil(callable $condition): void
    {
        $deadline = microtime(true) + 10;
        while (! $condition()) {
            if (microtime(true) >= $deadline) {
                $this->fail('Timed out waiting for concurrent workers.');
            }
            usleep(10000);
        }
    }
}
