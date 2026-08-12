<?php

declare(strict_types=1);

namespace App\Imports;

use App\Core\Auth;
use App\Core\Database;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Services\AssetTagger;

/**
 * Bulk import of assets from a spreadsheet — typically an existing register
 * being brought into the system for the first time.
 */
final class AssetImporter extends Importer
{
    public function key(): string
    {
        return 'assets';
    }

    public function name(): string
    {
        return 'Assets';
    }

    public function description(): string
    {
        return 'Bring an existing asset list in from a spreadsheet. Tags can be supplied or generated for you.';
    }

    public function permission(): string
    {
        return 'assets.create';
    }

    public function columns(): array
    {
        return [
            'asset_tag' => [
                'label' => 'Asset tag', 'aliases' => ['tag', 'asset id', 'asset number', 'barcode'],
                'description' => 'Leave blank to have one generated. Must be unique.',
                'example' => 'AST-1001',
            ],
            'name' => [
                'label' => 'Name', 'required' => true, 'aliases' => ['description short', 'item', 'title'],
                'description' => 'Required. What the item is.',
                'example' => 'Makita DHP484 Combi Drill',
            ],
            'description' => [
                'label' => 'Description', 'aliases' => ['details', 'long description'],
                'description' => 'Free text.',
                'example' => '18V brushless combi drill with two batteries',
            ],
            'category' => [
                'label' => 'Category', 'aliases' => ['category name', 'group'],
                'description' => 'Matched by name. Created if it does not exist (unless you turn that off).',
                'example' => 'Power Tools',
            ],
            'location' => [
                'label' => 'Location', 'aliases' => ['location name', 'where', 'site'],
                'description' => 'Matched by name. Created if it does not exist (unless you turn that off).',
                'example' => 'Main Workshop',
            ],
            'condition' => [
                'label' => 'Condition', 'aliases' => ['condition rating', 'state'],
                'description' => 'Excellent, Good, Fair, Poor or Out of Service. Defaults to Good.',
                'example' => 'Good',
            ],
            'status' => [
                'label' => 'Status',
                'description' => 'In Stock, In Maintenance, Faulty or Retired. Defaults to In Stock.',
                'example' => 'In Stock',
            ],
            'purchase_date' => [
                'label' => 'Purchase date', 'aliases' => ['bought', 'date purchased', 'acquired'],
                'description' => 'Accepts 2024-03-19, 19/03/2024 or 19 Mar 2024.',
                'example' => '2024-03-19',
            ],
            'purchase_cost' => [
                'label' => 'Purchase cost', 'aliases' => ['cost', 'price', 'value paid'],
                'description' => 'Currency symbols and commas are ignored.',
                'example' => '149.99',
            ],
            'current_value' => [
                'label' => 'Current value', 'aliases' => ['replacement value'],
                'description' => 'Optional.',
                'example' => '95.00',
            ],
            'supplier' => [
                'label' => 'Supplier', 'aliases' => ['bought from', 'vendor'],
                'description' => 'Optional.',
                'example' => 'Toolstation',
            ],
            'serial_number' => [
                'label' => 'Serial number', 'aliases' => ['serial', 'sn'],
                'description' => 'Optional. Duplicates are flagged as a warning, not an error.',
                'example' => 'MK-884213-A',
            ],
            'manufacturer' => [
                'label' => 'Manufacturer', 'aliases' => ['make', 'brand'],
                'description' => 'Optional.',
                'example' => 'Makita',
            ],
            'model' => [
                'label' => 'Model', 'aliases' => ['model number'],
                'description' => 'Optional.',
                'example' => 'DHP484Z',
            ],
            'manufacturer_url' => [
                'label' => 'Manufacturer URL', 'aliases' => ['website', 'product page', 'url'],
                'description' => 'Must start with http:// or https://.',
                'example' => 'https://www.makita.co.uk/product/dhp484.html',
            ],
            'plug_fuse_rating_amps' => [
                'label' => 'Plug fuse rating (A)', 'aliases' => ['fuse', 'fuse rating', 'fuse amps'],
                'description' => 'Amps, e.g. 3, 5 or 13.',
                'example' => '13',
            ],
            'cable_csa_mm2' => [
                'label' => 'Cable CSA (mm2)', 'aliases' => ['csa', 'cable csa', 'cable size', 'cable cross sectional area'],
                'description' => 'Square millimetres, e.g. 0.75 or 1.5.',
                'example' => '1.5',
            ],
            'requires_pat' => [
                'label' => 'Requires PAT', 'aliases' => ['pat', 'pat required', 'needs pat'],
                'description' => 'Yes/No, True/False or 1/0. Defaults to No.',
                'example' => 'Yes',
            ],
            'pat_interval_months' => [
                'label' => 'PAT interval (months)', 'aliases' => ['pat interval', 'retest interval'],
                'description' => 'Optional. Leave blank to use the site default.',
                'example' => '12',
            ],
            'is_hireable' => [
                'label' => 'Available for hire', 'aliases' => ['hireable', 'can be hired'],
                'description' => 'Yes/No. Defaults to Yes.',
                'example' => 'Yes',
            ],
            'notes' => [
                'label' => 'Notes', 'aliases' => ['comments', 'remarks'],
                'description' => 'Free text.',
                'example' => 'Supplied with a spare battery',
            ],
            'barcode' => [
                'label' => 'Secondary barcode', 'aliases' => ['other barcode', 'manufacturer barcode'],
                'description' => 'A barcode the item already carries, separate from its asset tag.',
                'example' => '',
            ],
            'warranty_expires_on' => [
                'label' => 'Warranty expires', 'aliases' => ['warranty', 'warranty end'],
                'description' => 'Optional date.',
                'example' => '',
            ],

            // Present so that a file exported from this system re-imports
            // cleanly. They are recognised and skipped rather than reported as
            // unknown columns.
            'parent_tag' => [
                'label' => 'Part of', 'ignore' => true,
                'description' => 'Ignored on import — attach sub-assets on the asset page after importing.',
                'example' => '',
            ],
            'relationship_type' => [
                'label' => 'Relationship', 'ignore' => true,
                'description' => 'Ignored on import.',
                'example' => '',
            ],
            'created_at' => [
                'label' => 'Added', 'ignore' => true,
                'description' => 'Ignored on import — the date the record was created here.',
                'example' => '',
            ],
        ];
    }

    public function optionDefinitions(): array
    {
        return [
            'create_categories' => [
                'label'       => 'Create categories and locations that do not exist yet',
                'description' => 'Off means rows naming an unknown category or location are still imported, but without it.',
                'default'     => true,
            ],
            'generate_tags' => [
                'label'       => 'Generate asset tags where the column is blank',
                'description' => 'Off means a row with no tag is skipped instead.',
                'default'     => true,
            ],
        ];
    }

    public function previewColumns(): array
    {
        return ['asset_tag', 'name', 'category', 'location', 'condition', 'requires_pat'];
    }

    public function notes(): array
    {
        return [
            'Only the Name column is required — everything else can be blank.',
            'Column headings are matched loosely: “Asset Tag”, “asset_tag” and “Tag” all work, and the order does not matter.',
            'A row with a tag that already exists is skipped, so re-running an import will not create duplicates.',
            'Nothing is written until you review the preview and confirm.',
        ];
    }

    public function newContext(): array
    {
        return [
            'tags'      => [],   // tags seen within this file
            'serials'   => [],
            'nextTags'  => [],
        ];
    }

    public function validateRow(array $row, array &$context, array $options): array
    {
        $errors   = [];
        $warnings = [];
        $data     = [];

        // --- Name (the only required field) ---
        $name = trim($row['name'] ?? '');

        if ($name === '') {
            $errors[] = 'Name is missing.';
        } elseif (mb_strlen($name) > 191) {
            $warnings[] = 'Name was longer than 191 characters and has been shortened.';
            $name       = mb_substr($name, 0, 191);
        }

        $data['name'] = $name;

        // --- Asset tag ---
        $tag = trim($row['asset_tag'] ?? '');

        if ($tag === '') {
            if (empty($options['generate_tags'])) {
                $errors[] = 'No asset tag, and tag generation is turned off.';
            } else {
                $tag        = $this->reserveGeneratedTag($context);
                $warnings[] = 'Tag generated: ' . $tag;
            }
        } else {
            if (mb_strlen($tag) > 64) {
                $errors[] = 'Asset tag is longer than 64 characters.';
            }

            $key = mb_strtolower($tag);

            if (isset($context['tags'][$key])) {
                $errors[] = 'Duplicate asset tag within this file (first seen on line ' . $context['tags'][$key] . ').';
            } elseif (Asset::tagExists($tag)) {
                $errors[] = 'An asset with the tag ' . $tag . ' already exists.';
            }

            $context['tags'][$key] = $row['__line'] ?? '?';
        }

        $data['asset_tag'] = $tag;

        // --- Simple text fields ---
        foreach (['description', 'supplier', 'manufacturer', 'model', 'notes'] as $field) {
            $value = trim($row[$field] ?? '');
            $data[$field] = $value !== '' ? $value : null;
        }

        // --- Serial number ---
        $serial = trim($row['serial_number'] ?? '');

        if ($serial !== '') {
            $serialKey = mb_strtolower($serial);

            if (isset($context['serials'][$serialKey])) {
                $warnings[] = 'Serial number also appears on line ' . $context['serials'][$serialKey] . ' of this file.';
            } else {
                $existing = Database::selectOne('SELECT asset_tag FROM assets WHERE serial_number = ? LIMIT 1', [$serial]);

                if ($existing !== null) {
                    $warnings[] = 'Serial number is already on ' . $existing['asset_tag'] . '.';
                }
            }

            $context['serials'][$serialKey] = $row['__line'] ?? '?';
        }

        $data['serial_number'] = $serial !== '' ? $serial : null;

        // --- Condition and status ---
        $condition = trim($row['condition'] ?? '');

        if ($condition === '') {
            $data['condition_rating'] = 'Good';
        } else {
            $matched = self::matchOption($condition, Asset::CONDITIONS);

            if ($matched === null) {
                $warnings[] = 'Condition "' . $condition . '" not recognised — using Good.';
                $matched    = 'Good';
            }

            $data['condition_rating'] = $matched;
        }

        $status = trim($row['status'] ?? '');

        if ($status === '') {
            $data['status'] = 'In Stock';
        } else {
            $matched = self::matchOption($status, Asset::STATUSES);

            if ($matched === null) {
                $warnings[] = 'Status "' . $status . '" not recognised — using In Stock.';
                $matched    = 'In Stock';
            }

            // An imported row cannot be "On Hire": there is no hire to attach.
            if ($matched === 'On Hire') {
                $warnings[] = 'Status On Hire cannot be set by import (there is no hire record) — using In Stock.';
                $matched    = 'In Stock';
            }

            $data['status'] = $matched;
        }

        $data['retired_on'] = $data['status'] === 'Retired' ? date('Y-m-d') : null;

        // --- Category and location ---
        $data['category_id'] = $this->resolveReference(
            'category',
            trim($row['category'] ?? ''),
            $context,
            $options,
            $warnings
        );

        $data['location_id'] = $this->resolveReference(
            'location',
            trim($row['location'] ?? ''),
            $context,
            $options,
            $warnings
        );

        // --- Dates and money ---
        $purchaseDate = trim($row['purchase_date'] ?? '');

        if ($purchaseDate !== '') {
            $parsed = self::parseDate($purchaseDate);

            if ($parsed === null) {
                $warnings[] = 'Purchase date "' . $purchaseDate . '" was not understood and has been left blank.';
            } elseif ($parsed > date('Y-m-d')) {
                $warnings[] = 'Purchase date is in the future.';
            }

            $data['purchase_date'] = $parsed;
        } else {
            $data['purchase_date'] = null;
        }

        foreach (['purchase_cost' => 'purchase_cost', 'current_value' => 'current_value'] as $field => $target) {
            $raw = trim($row[$field] ?? '');

            if ($raw === '') {
                $data[$target] = null;
                continue;
            }

            $number = self::parseNumber($raw);

            if ($number === null || $number < 0) {
                $warnings[] = ucfirst(str_replace('_', ' ', $field)) . ' "' . $raw . '" was not understood and has been left blank.';
                $data[$target] = null;
            } else {
                $data[$target] = round($number, 2);
            }
        }

        // --- Electrical ---
        foreach ([
            'plug_fuse_rating_amps' => ['label' => 'Plug fuse rating', 'max' => 999],
            'cable_csa_mm2'         => ['label' => 'Cable CSA', 'max' => 999],
        ] as $field => $meta) {
            $raw = trim($row[$field] ?? '');

            if ($raw === '') {
                $data[$field] = null;
                continue;
            }

            $number = self::parseNumber($raw);

            if ($number === null || $number < 0 || $number > $meta['max']) {
                $warnings[] = $meta['label'] . ' "' . $raw . '" was not understood and has been left blank.';
                $data[$field] = null;
            } else {
                $data[$field] = $number;
            }
        }

        $requiresPat = self::parseBool(trim($row['requires_pat'] ?? ''));
        $data['requires_pat'] = $requiresPat === true ? 1 : 0;

        $interval = trim($row['pat_interval_months'] ?? '');
        $intervalNumber = $interval === '' ? null : self::parseNumber($interval);

        if ($intervalNumber !== null && ($intervalNumber < 1 || $intervalNumber > 120)) {
            $warnings[] = 'PAT interval "' . $interval . '" is out of range and has been left blank.';
            $intervalNumber = null;
        }

        $data['pat_interval_months'] = $intervalNumber === null ? null : (int) $intervalNumber;

        $hireable = self::parseBool(trim($row['is_hireable'] ?? ''));
        $data['is_hireable'] = $hireable === false ? 0 : 1;

        // --- Manufacturer URL ---
        $url = trim($row['manufacturer_url'] ?? '');

        if ($url !== '') {
            if (preg_match('#^https?://#i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
                $warnings[] = 'Manufacturer URL "' . $url . '" is not a valid http(s) address and has been left blank.';
                $url = '';
            } elseif (mb_strlen($url) > 500) {
                $warnings[] = 'Manufacturer URL was too long and has been left blank.';
                $url = '';
            }
        }

        $data['manufacturer_url'] = $url !== '' ? $url : null;

        // --- Secondary barcode ---
        $barcode = trim($row['barcode'] ?? '');

        if ($barcode !== '') {
            if (mb_strlen($barcode) > 64) {
                $warnings[] = 'Secondary barcode is too long and has been left blank.';
                $barcode = '';
            } elseif (Asset::barcodeExists($barcode)) {
                $warnings[] = 'Secondary barcode "' . $barcode . '" is already used by another asset — left blank.';
                $barcode = '';
            }
        }

        $data['barcode'] = $barcode !== '' ? $barcode : null;

        // --- Warranty ---
        $warranty = trim($row['warranty_expires_on'] ?? '');

        if ($warranty !== '') {
            $parsed = self::parseDate($warranty);

            if ($parsed === null) {
                $warnings[] = 'Warranty expiry "' . $warranty . '" was not understood and has been left blank.';
            }

            $data['warranty_expires_on'] = $parsed;
        } else {
            $data['warranty_expires_on'] = null;
        }

        $status = $errors !== [] ? self::STATUS_ERROR : ($warnings !== [] ? self::STATUS_WARNING : self::STATUS_OK);

        return [
            'status'   => $status,
            'errors'   => $errors,
            'warnings' => $warnings,
            'data'     => $data,
            'summary'  => ($data['asset_tag'] !== '' ? $data['asset_tag'] . ' — ' : '') . $data['name'],
        ];
    }

    public function commitRow(array $data, array $options): string
    {
        // Pending category/location names are created now, not at preview time.
        foreach (['category' => 'category_id', 'location' => 'location_id'] as $type => $field) {
            if (is_string($data[$field] ?? null) && str_starts_with((string) $data[$field], 'new:')) {
                $name = substr((string) $data[$field], 4);

                $data[$field] = $type === 'category'
                    ? Category::create($name, null, null)
                    : Location::create($name, null, null, null);
            }
        }

        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        unset($data['__line']);

        $id = Asset::create($data);

        return sprintf('%s (%s)', $data['asset_tag'], $data['name']);
    }

    /**
     * Resolve a category or location name to an id, or to a "new:" marker
     * that commitRow turns into a real record.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $options
     * @param array<int,string>   $warnings
     */
    private function resolveReference(string $type, string $name, array &$context, array $options, array &$warnings): int|string|null
    {
        if ($name === '') {
            return null;
        }

        $cacheKey = $type . 's';
        $lookup   = mb_strtolower($name);

        if (!isset($context[$cacheKey])) {
            $context[$cacheKey] = [];

            $rows = $type === 'category'
                ? Category::all()
                : Location::all();

            foreach ($rows as $record) {
                $context[$cacheKey][mb_strtolower((string) $record['name'])] = (int) $record['id'];
            }
        }

        if (isset($context[$cacheKey][$lookup])) {
            return $context[$cacheKey][$lookup];
        }

        if (empty($options['create_categories'])) {
            $warnings[] = ucfirst($type) . ' "' . $name . '" does not exist and will not be created — left blank.';

            return null;
        }

        $warnings[] = ucfirst($type) . ' "' . $name . '" will be created.';

        // Remembered so later rows naming the same thing agree, and so the
        // preview does not promise to create it twice.
        $context[$cacheKey][$lookup] = 'new:' . $name;

        return 'new:' . $name;
    }

    /**
     * Reserve a generated tag, taking account of ones already handed out
     * earlier in this same file.
     *
     * @param array<string,mixed> $context
     */
    private function reserveGeneratedTag(array &$context): string
    {
        $needed = count($context['nextTags'] ?? []);

        // Ask for a fresh batch when the reserve runs out.
        if ($needed === 0) {
            $context['nextTags'] = AssetTagger::nextBatch(50);

            // Drop any already used earlier in this file.
            $context['nextTags'] = array_values(array_filter(
                $context['nextTags'],
                static fn (string $tag): bool => !isset($context['tags'][mb_strtolower($tag)])
            ));
        }

        $tag = array_shift($context['nextTags']) ?? (AssetTagger::prefix() . strtoupper(bin2hex(random_bytes(3))));

        $context['tags'][mb_strtolower($tag)] = 'generated';

        return $tag;
    }
}
