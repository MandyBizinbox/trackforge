<?php

namespace Database\Seeders;

use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time initial stock registration for a selected Scout unit.
 *
 * Usage (after reviewing production stock):
 * TRACKFORGE_SEED_UNIT_ID=123 php artisan db:seed --class=InitialScoutEquipmentSeeder --force
 *
 * This seeder never changes quantities or other attributes of existing assets.
 */
class InitialScoutEquipmentSeeder extends Seeder
{
    public function run(): void
    {
        $rawUnitId = getenv('TRACKFORGE_SEED_UNIT_ID');

        if (! is_string($rawUnitId) || ! ctype_digit($rawUnitId) || (int) $rawUnitId < 1) {
            throw new RuntimeException(
                'Set TRACKFORGE_SEED_UNIT_ID to the verified numeric unit ID before seeding.'
            );
        }

        $unit = Unit::query()->findOrFail((int) $rawUnitId);

        $categories = EquipmentCategory::query()
            ->whereIn('slug', ['tents', 'pioneering'])
            ->get()
            ->keyBy('slug');

        foreach (['tents', 'pioneering'] as $slug) {
            if (! $categories->has($slug)) {
                throw new RuntimeException(
                    "Equipment category '{$slug}' is missing. Seed categories first."
                );
            }
        }

        $records = [
            ['code' => 'GAZ-001', 'name' => 'Gazebo 1', 'category' => 'tents', 'tracking' => 'individual', 'quantity' => 1],
            ['code' => 'GAZ-002', 'name' => 'Gazebo 2', 'category' => 'tents', 'tracking' => 'individual', 'quantity' => 1],
            ['code' => 'TENT-AF-001', 'name' => 'A-Frame Tent 1', 'category' => 'tents', 'tracking' => 'individual', 'quantity' => 1],
            ['code' => 'TENT-AF-002', 'name' => 'A-Frame Tent 2', 'category' => 'tents', 'tracking' => 'individual', 'quantity' => 1],
            ['code' => 'TENT-AF-003', 'name' => 'A-Frame Tent 3', 'category' => 'tents', 'tracking' => 'individual', 'quantity' => 1],
            ['code' => 'TENT-AF-004', 'name' => 'A-Frame Tent 4', 'category' => 'tents', 'tracking' => 'individual', 'quantity' => 1],
            ['code' => 'ROPE-4M', 'name' => '4m Rope', 'category' => 'pioneering', 'tracking' => 'quantity', 'quantity' => 100],
            ['code' => 'ROPE-6M', 'name' => '6m Rope', 'category' => 'pioneering', 'tracking' => 'quantity', 'quantity' => 50],
        ];

        DB::transaction(function () use ($unit, $categories, $records): void {
            $created = 0;
            $skipped = 0;

            foreach ($records as $record) {
                $existing = EquipmentItem::withTrashed()
                    ->where('asset_code', $record['code'])
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $matching = ! $existing->trashed()
                        && (int) $existing->unit_id === (int) $unit->id
                        && (int) $existing->category_id === (int) $categories[$record['category']]->id
                        && $existing->name === $record['name']
                        && $existing->tracking_type === $record['tracking']
                        && (int) $existing->quantity_total === $record['quantity'];

                    if (! $matching) {
                        throw new RuntimeException(
                            "Asset code {$record['code']} already exists with different data. Nothing was seeded."
                        );
                    }

                    $skipped++;
                    continue;
                }

                EquipmentItem::create([
                    'unit_id' => $unit->id,
                    'category_id' => $categories[$record['category']]->id,
                    'asset_code' => $record['code'],
                    'name' => $record['name'],
                    'tracking_type' => $record['tracking'],
                    'quantity_total' => $record['quantity'],
                    'quantity_available' => $record['quantity'],
                    'condition_status' => 'good',
                    'availability_status' => 'available',
                    'is_consumable' => false,
                    'notes' => 'Initial inventory registration; physically verify before first issue.',
                ]);
                $created++;
            }

            $this->command?->info(
                "Unit: {$unit->name} (#{$unit->id}). Created {$created}; existing matching assets {$skipped}."
            );
        });
    }
}
