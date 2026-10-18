<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/** Fixed identifiers make public JSON contracts independent of auto-increment state. */
final class PublicProductApiFixture
{
    public const SERIES_PATH = 'electronics/power-switches';

    public static function seed(): void
    {
        $connection = DB::connection('catalog');
        $connection->table('category')->insert([
            ['id' => 100, 'parent_id' => null, 'name' => 'Electronics', 'type' => 'category', 'display_order' => 1],
            ['id' => 200, 'parent_id' => null, 'name' => 'Empty Category', 'type' => 'category', 'display_order' => 2],
        ]);
        $connection->table('category')->insert([
            ['id' => 110, 'parent_id' => 100, 'name' => 'Power Switches', 'type' => 'series', 'display_order' => 2],
            ['id' => 120, 'parent_id' => 100, 'name' => 'Accessories', 'type' => 'category', 'display_order' => 1],
            ['id' => 130, 'parent_id' => 100, 'name' => '測定 Étage', 'type' => 'category', 'display_order' => 3],
            ['id' => 140, 'parent_id' => 100, 'name' => 'Blank Series', 'type' => 'series', 'display_order' => 4],
            ['id' => 150, 'parent_id' => 100, 'name' => 'Switch!', 'type' => 'series', 'display_order' => 5],
            ['id' => 151, 'parent_id' => 100, 'name' => 'Switch?', 'type' => 'series', 'display_order' => 5],
        ]);
        $connection->table('series_custom_field')->insert([
            self::field(1001, 'voltage', 'Voltage', 'number', 'product_attribute', '5', 1, true),
            self::field(1002, 'finish', 'Finish', 'text', 'product_attribute', 'Silver', 1, false, false, true),
            self::field(1003, 'optional', 'Optional', 'text', 'product_attribute', null, 2),
            self::field(1004, 'secret', 'Internal secret', 'text', 'product_attribute', 'DO NOT EXPOSE', 0, false, true),
            self::field(1010, 'heading', 'Heading', 'text', 'series_metadata', 'Default heading', 1),
            self::field(1011, 'spec', 'Specification', 'file', 'series_metadata', 'unused.pdf', 2),
            self::field(1012, 'missing', 'Missing', 'text', 'series_metadata', null, 3),
            self::field(1013, 'empty', 'Empty', 'text', 'series_metadata', 'Fallback', 4),
            self::field(1014, 'private_metadata', 'Private metadata', 'text', 'series_metadata', 'PRIVATE', 0, false, true),
        ]);
        $connection->table('series_custom_field_value')->insert([
            ['id' => 3001, 'series_id' => 110, 'series_custom_field_id' => 1011, 'value' => 'media/spec sheet.pdf'],
            ['id' => 3002, 'series_id' => 110, 'series_custom_field_id' => 1013, 'value' => ''],
        ]);
        $connection->table('product')->insert([
            ['id' => 2001, 'series_id' => 110, 'sku' => 'SW-10', 'name' => 'Alpha', 'description' => null],
            ['id' => 2002, 'series_id' => 110, 'sku' => 'SW-2', 'name' => 'Alpha', 'description' => 'Second part'],
            ['id' => 2003, 'series_id' => 110, 'sku' => 'SW-1', 'name' => 'Beta', 'description' => 'Third part'],
            ['id' => 2004, 'series_id' => 110, 'sku' => 'SW-20', 'name' => 'Gamma', 'description' => '測定 description'],
        ]);
        $connection->table('product_custom_field_value')->insert([
            ['id' => 4001, 'product_id' => 2001, 'series_custom_field_id' => 1001, 'value' => '10'],
            ['id' => 4002, 'product_id' => 2001, 'series_custom_field_id' => 1002, 'value' => 'Gold'],
            ['id' => 4003, 'product_id' => 2001, 'series_custom_field_id' => 1003, 'value' => ''],
            ['id' => 4004, 'product_id' => 2001, 'series_custom_field_id' => 1004, 'value' => 'CLASSIFIED'],
            ['id' => 4005, 'product_id' => 2002, 'series_custom_field_id' => 1001, 'value' => '2'],
            ['id' => 4006, 'product_id' => 2002, 'series_custom_field_id' => 1002, 'value' => 'gold'],
            ['id' => 4007, 'product_id' => 2002, 'series_custom_field_id' => 1003, 'value' => null],
            ['id' => 4008, 'product_id' => 2004, 'series_custom_field_id' => 1001, 'value' => '10'],
            ['id' => 4009, 'product_id' => 2004, 'series_custom_field_id' => 1002, 'value' => 'Gold'],
            ['id' => 4010, 'product_id' => 2004, 'series_custom_field_id' => 1003, 'value' => '0'],
        ]);
    }

    /** @return array<string, mixed> */
    public static function root(): array
    {
        return ['id' => 100, 'parentId' => null, 'name' => 'Electronics', 'type' => 'category', 'slug' => 'electronics', 'path' => 'electronics', 'displayOrder' => 1];
    }

    /** @return array<string, mixed> */
    public static function series(): array
    {
        return ['id' => 110, 'parentId' => 100, 'name' => 'Power Switches', 'type' => 'series', 'slug' => 'power-switches', 'path' => self::SERIES_PATH, 'displayOrder' => 2];
    }

    /** @return list<array<string, mixed>> */
    public static function fields(): array
    {
        return [
            ['id' => 1001, 'key' => 'voltage', 'label' => 'Voltage', 'type' => 'number', 'defaultValue' => '5', 'sortOrder' => 1, 'required' => true],
            ['id' => 1002, 'key' => 'finish', 'label' => 'Finish', 'type' => 'text', 'defaultValue' => 'Silver', 'sortOrder' => 1, 'required' => false],
            ['id' => 1003, 'key' => 'optional', 'label' => 'Optional', 'type' => 'text', 'defaultValue' => null, 'sortOrder' => 2, 'required' => false],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function metadata(): array
    {
        return [
            ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'value' => 'Default heading', 'sortOrder' => 1],
            ['key' => 'spec', 'label' => 'Specification', 'type' => 'file', 'value' => 'media/spec sheet.pdf', 'sortOrder' => 2],
            ['key' => 'missing', 'label' => 'Missing', 'type' => 'text', 'value' => null, 'sortOrder' => 3],
            ['key' => 'empty', 'label' => 'Empty', 'type' => 'text', 'value' => '', 'sortOrder' => 4],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function parts(): array
    {
        return [
            ['id' => 2003, 'sku' => 'SW-1', 'name' => 'Beta', 'description' => 'Third part', 'values' => ['voltage' => '5', 'finish' => 'Silver', 'optional' => null]],
            ['id' => 2002, 'sku' => 'SW-2', 'name' => 'Alpha', 'description' => 'Second part', 'values' => ['voltage' => '2', 'finish' => 'gold', 'optional' => null]],
            ['id' => 2001, 'sku' => 'SW-10', 'name' => 'Alpha', 'description' => null, 'values' => ['voltage' => '10', 'finish' => 'Gold', 'optional' => '']],
            ['id' => 2004, 'sku' => 'SW-20', 'name' => 'Gamma', 'description' => '測定 description', 'values' => ['voltage' => '10', 'finish' => 'Gold', 'optional' => '0']],
        ];
    }

    /** @return array<string, mixed> */
    private static function field(int $id, string $key, string $label, string $type, string $scope, ?string $default, int $order, bool $required = false, bool $publicHidden = false, bool $backendHidden = false): array
    {
        return ['id' => $id, 'series_id' => 110, 'field_key' => $key, 'label' => $label, 'field_type' => $type, 'field_scope' => $scope, 'default_value' => $default, 'sort_order' => $order, 'is_required' => (int) $required, 'is_public_portal_hidden' => (int) $publicHidden, 'is_backend_portal_hidden' => (int) $backendHidden];
    }
}
