<?php

namespace Modules\Shopping;

// Starter aisle sets for a shop. Each aisle is [slug, name, icon] where icon is a slug from the
// frontend AISLE_ICONS pack. The 'grocery' template is also the fallback for shops with no custom
// rows, so its slugs MUST match the legacy item department slugs (produce, dairy, ...).
class StoreTemplates
{
    /** @var array<string, list<array{0:string,1:string,2:string}>> */
    public const TEMPLATES = [
        'grocery' => [
            ['produce', 'Produce', 'carrot'],
            ['dairy', 'Dairy & Eggs', 'milk'],
            ['bakery', 'Bakery', 'croissant'],
            ['pantry', 'Pantry', 'package'],
            ['household', 'Household', 'spray-can'],
            ['frozen', 'Frozen', 'snowflake'],
            ['meat', 'Meat', 'beef'],
            ['beverages', 'Beverages', 'cup-soda'],
            ['other', 'Other', 'basket'],
        ],
        'bricolage' => [
            ['hand-tools', 'Hand tools', 'wrench'],
            ['power-tools', 'Power tools', 'drill'],
            ['gardening', 'Gardening', 'sprout'],
            ['plumbing', 'Plumbing', 'droplets'],
            ['electrical', 'Electrical', 'zap'],
            ['paint', 'Paint', 'paint-roller'],
            ['hardware', 'Hardware', 'bolt'],
            ['other', 'Other', 'basket'],
        ],
        'pharmacy' => [
            ['medicine', 'Medicine', 'pill'],
            ['personal-care', 'Personal care', 'sparkles'],
            ['baby', 'Baby', 'baby'],
            ['vitamins', 'Vitamins', 'tablets'],
            ['household', 'Household', 'spray-can'],
            ['other', 'Other', 'basket'],
        ],
        'blank' => [
            ['other', 'Other', 'basket'],
        ],
    ];

    // Shown in the editor's "Start from a template" picker.
    public const LABELS = [
        'grocery' => 'Grocery',
        'bricolage' => 'Bricolage / DIY',
        'pharmacy' => 'Pharmacy',
        'blank' => 'Blank',
    ];

    /** The aisle set a shop with no custom rows uses (keeps legacy grocery lists grouping). */
    public static function default(): array
    {
        return self::TEMPLATES['grocery'];
    }

    /** @return list<array{key:string,label:string}> for the template picker */
    public static function options(): array
    {
        $out = [];
        foreach (self::LABELS as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label];
        }

        return $out;
    }
}
