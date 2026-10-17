<?php

namespace Modules\Spending;

/**
 * Every currency a list, an expense or a display can use: the ISO 4217 codes our exchange-rate sources
 * can convert (Fx: ECB via frankfurter for ~30 of them, open.er-api.com for the rest), so a spend never
 * silently converts 1:1. Left out on purpose: non-ISO or local codes (CNH, CLF, XDR, FOK, GGP, IMP, JEP,
 * KID, TVD) and retired ones (HRK -> EUR, SLL -> SLE, ZWL -> ZWG, ANG -> XCG).
 *
 * Amounts stay integer hundredths for every currency (no per-currency minor units): JPY and friends just
 * display without decimals (Intl/NumberFormatter), and Fx converts hundredths to hundredths with a plain
 * multiply. ponytail: the 3-decimal currencies (BHD, IQD, JOD, KWD, LYD, OMR, TND) round to 2 decimals;
 * store real minor units per currency if anyone ever needs the third (fils/baisa) digit.
 */
final class Currencies
{
    // The default when a family hasn't picked one (and the old list's first entry): never ALL[0], which
    // is just alphabetical (AED).
    public const FALLBACK = 'EUR';

    /**
     * The currency used in a country (ISO 3166 alpha-2), from ICU's locale data, so there is no country
     * list to maintain: RO -> RON, DE -> EUR, XK -> EUR. Null for a country with no currency we support
     * (ICU answers XXX, e.g. Antarctica).
     */
    public static function forCountry(?string $country): ?string
    {
        if (! $country) {
            return null;
        }
        $code = (new \NumberFormatter('en_'.strtoupper($country), \NumberFormatter::CURRENCY))->getTextAttribute(\NumberFormatter::CURRENCY_CODE);

        return in_array($code, self::ALL, true) ? $code : null;
    }

    public const ALL = [
        'AED', 'AFN', 'ALL', 'AMD', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD',
        'BND', 'BOB', 'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CUP',
        'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS', 'GIP',
        'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'IQD', 'IRR', 'ISK', 'JMD', 'JOD',
        'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'LYD', 'MAD',
        'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO',
        'NOK', 'NPR', 'NZD', 'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF',
        'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SSP', 'STN', 'SYP', 'SZL', 'THB', 'TJS',
        'TMT', 'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'VES', 'VND', 'VUV', 'WST',
        'XAF', 'XCD', 'XCG', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW', 'ZWG',
    ];
}
