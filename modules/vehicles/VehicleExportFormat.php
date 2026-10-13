<?php

namespace Modules\Vehicles;

use App\Support\Translations;
use IntlDateFormatter;
use NumberFormatter;

/**
 * Formatting for the export, in the reader's locale: money in the car currency ("408,53 lei" in ro,
 * "RON 408.53" in en), dates, distances and fuel in the family's unit, labels from lang/<lang>/vehicles.json.
 */
class VehicleExportFormat
{
    private const LITRES_PER_US_GALLON = 3.785411784;

    private const MPG_L100 = 235.214583; // same constant as resources/js/lib/vehicles.ts

    private NumberFormatter $money;

    private NumberFormatter $num;

    private IntlDateFormatter $date;

    public function __construct(public string $locale, public string $currency, public string $unit, public string $lang)
    {
        $this->money = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $this->num = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $this->date = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC');
    }

    public function money(?float $amount): string
    {
        return $amount === null ? '' : (string) $this->money->formatCurrency($amount, $this->currency);
    }

    public function num(float $n, int $decimals = 0): string
    {
        $this->num->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $this->num->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        return (string) $this->num->format($n);
    }

    public function date(?string $ymd): string
    {
        return $ymd ? (string) $this->date->format(new \DateTimeImmutable($ymd.' 00:00:00', new \DateTimeZone('UTC'))) : '—';
    }

    public function today(): string
    {
        return $this->date(now()->toDateString());
    }

    public function distanceUnit(): string
    {
        return VehicleUnits::label($this->unit);
    }

    public function km(?int $km): string
    {
        return $km === null ? '' : $this->num(VehicleUnits::fromKm($km, $this->unit)).' '.$this->distanceUnit();
    }

    public function volume(float $liters): string
    {
        return $this->unit === 'imperial'
            ? $this->num($liters / self::LITRES_PER_US_GALLON, 2).' gal'
            : $this->num($liters, 2).' L';
    }

    public function consumption(?float $lPer100): string
    {
        if (! $lPer100) {
            return '—';
        }

        return $this->unit === 'imperial'
            ? $this->num(self::MPG_L100 / $lPer100, 1).' mpg'
            : $this->num($lPer100, 1).' L/100km';
    }

    public function costPerDistance(?float $perKm): string
    {
        if ($perKm === null) {
            return '—';
        }
        $per = $this->unit === 'imperial' ? $perKm * VehicleUnits::KM_PER_MILE : $perKm;

        return $this->money($per).' / '.$this->distanceUnit();
    }

    /** A vehicles.<key> label, with :placeholders replaced. */
    public function t(string $key, array $replace = []): string
    {
        // strtr replaces longest keys first, so :page never eats the start of :pages.
        return strtr(Translations::get("vehicles.{$key}", $this->lang), collect($replace)->mapWithKeys(fn ($v, $k) => [':'.$k => (string) $v])->all());
    }
}
