<?php

namespace Modules\Vehicles;

use App\Support\Media\StoredImage;
use App\Support\Tenancy\FamilyClock;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

/**
 * "Export history": one car's whole service life as a printable A4 PDF (Vehicles > History button and
 * the vehicles_export_history MCP tool). data() is the pure report (testable); pdf() renders it with
 * dompdf, a pure-PHP renderer, so the server needs no Chrome and no image extension.
 *
 * Money: every cost column (maintenance/repair/fuel `cost`) and the mirrored ad-hoc expenses are in the
 * car currency (VehicleExpenses::currency), so sums never need FX. Distances are stored in km and shown in
 * the family's unit; fuel volume/consumption follow it too (US gallons / mpg for imperial, like the app).
 */
class VehicleHistoryExport
{
    /** Report sections, in print order. Costed ones count towards "total spent". */
    public const CATEGORIES = ['maintenance', 'repair', 'fuel', 'expense', 'tire', 'document', 'mileage'];

    /**
     * @return array{vehicle: array, currency: string, unit: string, summary: array, years: list<array>, totals: array<string, float>}
     */
    public static function data(Vehicle $v): array
    {
        $v->loadMissing(['maintenanceHistory.serviceProvider', 'repairs.serviceProvider', 'documents', 'mileageLogs', 'tireSets', 'fuelEntries']);
        $unit = VehicleSetting::current()->unit_system;

        // The timeline already merges maintenance, repairs, documents, ad-hoc expenses, tyre changes and
        // mileage readings (money shown once per real event). Fuel fills live on their own tab, so add them.
        $rows = collect(VehicleTimeline::forVehicle($v))->map(fn (array $e) => [
            'category' => $e['type'],
            'date' => $e['date'],
            'km' => $e['mileage'],
            'title' => $e['title'],
            'detail' => $e['type'] === 'document' ? null : $e['subtitle'],
            'doc_type' => $e['type'] === 'document' ? $e['subtitle'] : null,
            'cost' => $e['cost'],
        ]);
        foreach ($v->fuelEntries as $f) {
            $rows->push([
                'category' => 'fuel',
                'date' => $f->filled_on?->toDateString(),
                'km' => $f->mileage,
                'title' => $f->station,
                'detail' => null,
                'liters' => (float) $f->liters,
                'full' => (bool) $f->is_full_tank,
                'cost' => $f->cost,
            ]);
        }

        // Newest year first; inside a section oldest first, so a year reads like a logbook.
        $years = $rows->groupBy(fn ($r) => $r['date'] ? substr($r['date'], 0, 4) : '—')
            ->sortKeysDesc()
            ->map(function ($inYear, $year) {
                $groups = [];
                foreach (self::CATEGORIES as $cat) {
                    $items = $inYear->where('category', $cat)->sortBy([['date', 'asc'], ['km', 'asc']])->values();
                    if ($items->isNotEmpty()) {
                        $groups[$cat] = ['rows' => $items->all(), 'total' => round((float) $items->sum('cost'), 2)];
                    }
                }

                return ['year' => (string) $year, 'groups' => $groups, 'total' => round((float) $inYear->sum('cost'), 2)];
            })->values()->all();

        $totals = [];
        foreach (self::CATEGORIES as $cat) {
            $totals[$cat] = round((float) $rows->where('category', $cat)->sum('cost'), 2);
        }
        $spent = round(array_sum($totals), 2);

        // Cost per km over the distance the records span: from the lowest odometer on file to today's.
        $firstKm = $rows->pluck('km')->filter(fn ($km) => $km !== null)->min();
        $distance = $firstKm !== null ? (int) $v->current_mileage - (int) $firstKm : 0;
        $fuel = FuelStats::forEntries($v->fuelEntries);

        return [
            'vehicle' => [
                'name' => trim("{$v->make} {$v->model}"),
                'version' => $v->version,
                'year' => $v->year,
                'plate' => $v->license_plate ?: $v->registration_number,
                'vin' => $v->vin,
                'fuel_type' => $v->fuel_type,
                'photo' => self::photo($v),
            ],
            'currency' => VehicleExpenses::currency((int) $v->family_id),
            'unit' => $unit,
            'summary' => [
                'mileage_km' => (int) $v->current_mileage,
                'spent' => $spent,
                'cost_per_km' => $distance > 0 && $spent > 0 ? $spent / $distance : null,
                'avg_l_per_100km' => $fuel['avgConsumption'],
            ],
            'years' => $years,
            'totals' => $totals,
        ];
    }

    /** The rendered PDF bytes. $lang = label language (app locale), $numberLocale = number/date format. */
    public static function pdf(Vehicle $v, string $lang, string $numberLocale): string
    {
        $data = self::data($v);
        $f = new VehicleExportFormat($numberLocale, $data['currency'], $data['unit'], $lang);

        $fontDir = __DIR__.'/resources/fonts';
        $cache = storage_path('framework/cache/dompdf');
        if (! is_dir($cache)) {
            mkdir($cache, 0775, true);
        }
        $options = new Options([
            'fontDir' => $cache,
            'fontCache' => $cache,
            'tempDir' => sys_get_temp_dir(),
            'chroot' => [$fontDir],          // only our bundled fonts may be read from disk
            'isRemoteEnabled' => false,       // the photo is inlined; nothing is fetched while rendering
            'defaultFont' => 'Inter',
            'defaultPaperSize' => 'a4',
            'dpi' => 96,
        ]);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('vehicles::history-pdf', ['d' => $data, 'f' => $f, 'fontDir' => $fontDir])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        // Page numbers: drawn on every page after layout, when the page count is known.
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('Inter');
        $canvas->page_text(
            $canvas->get_width() - 100, $canvas->get_height() - 34, // ~right margin, on the footer line
            $f->t('export_page', ['page' => '{PAGE_NUM}', 'pages' => '{PAGE_COUNT}']),
            $font, 8, [0.45, 0.45, 0.42],
        );

        return (string) $pdf->output();
    }

    public static function filename(Vehicle $v): string
    {
        $name = preg_replace('/[^A-Za-z0-9]+/', '-', trim("{$v->make} {$v->model} ".($v->license_plate ?: '')));

        return trim((string) $name, '-').'-history-'.FamilyClock::today().'.pdf';
    }

    /**
     * The car photo as a data URI, or null. Only JPEG is embedded: dompdf reads JPEG natively but needs the
     * GD extension (not installed) for PNG/WebP. The 640px thumb is always a JPEG (client-generated, see
     * StoredImage), sharp enough for the header and keeps the PDF small; fall back to the original.
     */
    private static function photo(Vehicle $v): ?string
    {
        if (! $v->photo_path) {
            return null;
        }
        $disk = Storage::disk('r2');
        foreach ([StoredImage::thumbPath($v->photo_path), $v->photo_path] as $path) {
            $bytes = rescue(fn () => $disk->get($path), null, false);
            if (is_string($bytes) && str_starts_with($bytes, "\xFF\xD8\xFF")) {
                return 'data:image/jpeg;base64,'.base64_encode($bytes);
            }
        }

        return null;
    }
}
