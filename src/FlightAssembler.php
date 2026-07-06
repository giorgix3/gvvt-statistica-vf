<?php

declare(strict_types=1);

namespace Gvvt;

/**
 * Business logic ported from backend.py.
 *
 * Three responsibilities:
 *   1. assembleFlights()  — pair tow-plane flights with their glider
 *   2. prepareOutput()    — format the row data for the .xlsx export
 *   3. prepareSummary()   — count landings per aircraft for the HTML table
 */
class FlightAssembler
{
    // Aircraft excluded from all output
    private const EXCLUDED_AIRCRAFT = ['T7-PCS', 'HB-SDK', 'HB-SDV', 'HB-PEH', 'HB-RBN', 'HB-RBC', 'HB-WAW'];

    // Aircraft excluded only from the .xlsx export
    private const EXCLUDED_FROM_EXPORT = ['F-PTUN', 'HB-KMW', 'HB-RCQ'];

    // ftid values that mean "training flight"
    private const FTID_TRAINING = [8, 11, 12];

    // ftid value that means "passenger flight"
    private const FTID_PAX = 4;

    // starttype value meaning "aerotow"
    private const STARTTYPE_AEROTOW = 3;

    // -------------------------------------------------------------------------

    /**
     * Given a flat list of raw flight arrays, return a flat list where each
     * aerotow glider flight is merged with its tow-plane flight.
     * Self-launching flights are included as-is (except pure tow-planes).
     */
    public function assembleFlights(array $flights): array
    {
        $assembled = [];

        foreach ($flights as $flight) {
            if (empty($flight)) {
                continue;
            }

            $callsign     = $flight['callsign'] ?? '';
            $departure    = $flight['departurelocation'] ?? '';
            $arrival      = $flight['arrivallocation'] ?? '';

            // Only keep flights that touched Locarno LSZL
            if ($departure !== 'Locarno LSZL' && $arrival !== 'Locarno LSZL') {
                continue;
            }

            if (in_array($callsign, self::EXCLUDED_AIRCRAFT, true)) {
                continue;
            }

            $flidTow   = (int)($flight['flidtow'] ?? 0);
            $startType = (int)($flight['starttype'] ?? 0);
            $ftid      = (int)($flight['ftid'] ?? 0);

            if ($flidTow !== 0 && $startType === self::STARTTYPE_AEROTOW) {
                // Glider with an aerotow: find and merge the tow-plane record
                $towFlight = $this->findById($flights, (string)$flidTow);
                if ($towFlight === null) {
                    // Tow flight missing — include glider alone so data isn't lost
                    $assembled[] = $flight;
                    continue;
                }
                // Merge: tow-plane keys are primary; glider keys get suffix "_gld"
                $merged = $towFlight;
                foreach ($flight as $key => $value) {
                    $merged[$key . '_gld'] = $value;
                }
                $assembled[] = $merged;
            } elseif ($ftid !== self::STARTTYPE_AEROTOW) {
                // Self-launching flight (not a pure tow-plane)
                $assembled[] = $flight;
            }
        }

        return $assembled;
    }

    /**
     * Format assembled flights for .xlsx export.
     * Returns an array of row arrays, each keyed by the field names below.
     */
    public function prepareOutput(array $assembledFlights): array
    {
        $fields = ['RWY','RWYPOS','DATUM','SP','SPPILOT','SF','SFPILOT',
                   'SCHULUNG','PAX','SPSTART','SPLANDUNG','SFSTART','SFLANDUNG','GRUPPE','PRIVAT'];
        $out = [];

        foreach ($assembledFlights as $flight) {
            $callsign = $flight['callsign'] ?? '';
            if (in_array($callsign, self::EXCLUDED_FROM_EXPORT, true)) {
                continue;
            }

            $row              = array_fill_keys($fields, '');
            $row['RWY']       = 1;
            $row['RWYPOS']    = 1;
            $row['DATUM']     = $this->formatDate($flight['dateofflight'] ?? '');
            $row['SP']        = $callsign;
            $row['SPPILOT']   = $this->cleanName($flight['pilotname'] ?? '');
            $row['SPSTART']   = $flight['departuretime'] ?? '';
            $row['SPLANDUNG'] = $flight['arrivaltime'] ?? '';

            if (isset($flight['callsign_gld'])) {
                // Aerotow pair
                $ftidGld        = (int)($flight['ftid_gld'] ?? 0);
                $row['SF']      = $flight['callsign_gld'];
                $row['SFPILOT'] = $this->cleanName($flight['pilotname_gld'] ?? '');
                [$row['SCHULUNG'], $row['PAX']] = $this->classifyFlight(
                    $ftidGld,
                    $flight['attendantname_gld'] ?? ''
                );
                $row['SFSTART']   = $this->trimSeconds($flight['departuretime_gld'] ?? '');
                $row['SFLANDUNG'] = $this->trimSeconds($flight['arrivaltime_gld'] ?? '');
            } else {
                // Self-launching
                $ftid = (int)($flight['ftid'] ?? 0);
                [$row['SCHULUNG'], $row['PAX']] = $this->classifyFlight(
                    $ftid,
                    $flight['attendantname'] ?? ''
                );
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * Count landings per aircraft, broken down by flight type.
     *
     * Returns an array keyed by callsign, each value an array with keys:
     *   TS, TN, TP  (tow: school/normal/pax)
     *   AS, AN, AP  (autonomous: school/normal/pax)
     *   DG          (cross-border flights)
     *   TT, AT, GT  (totals)
     */
    public function prepareSummary(array $assembledFlights): array
    {
        $zero     = ['TS'=>0,'TN'=>0,'TP'=>0,'AS'=>0,'AN'=>0,'AP'=>0,'DG'=>0,'TT'=>0,'AT'=>0,'GT'=>0];
        $aircraft = [];

        foreach ($assembledFlights as $flight) {
            $callsign = $flight['callsign'] ?? '';
            if (!isset($aircraft[$callsign])) {
                $aircraft[$callsign] = $zero;
            }

            $landings = (int)($flight['landingcount'] ?? 1);

            if (isset($flight['ftid_gld'])) {
                // Tow-plane: classify by glider's ftid
                $ftidGld = (int)($flight['ftid_gld'] ?? 0);
                [$schulung, $pax] = $this->classifyFlight($ftidGld, $flight['attendantname_gld'] ?? '');
                if ($schulung) {
                    $aircraft[$callsign]['TS'] += $landings;
                } elseif ($pax) {
                    $aircraft[$callsign]['TP'] += $landings;
                } else {
                    $aircraft[$callsign]['TN'] += $landings;
                }
            } else {
                // Autonomous flight
                $ftid = (int)($flight['ftid'] ?? 0);
                [$schulung, $pax] = $this->classifyFlight($ftid, $flight['attendantname'] ?? '');
                if ($schulung) {
                    $aircraft[$callsign]['AS'] += $landings;
                } elseif ($pax) {
                    $aircraft[$callsign]['AP'] += $landings;
                } else {
                    $aircraft[$callsign]['AN'] += $landings;
                }

                // Cross-border: neither departure nor arrival ends in "LS"
                $dep = $flight['departurelocation'] ?? '';
                $arr = $flight['arrivallocation'] ?? '';
                if (substr($dep, -4, 2) !== 'LS' || substr($arr, -4, 2) !== 'LS') {
                    $aircraft[$callsign]['DG'] += 1;
                }
            }
        }

        // Compute totals
        foreach ($aircraft as $cs => &$row) {
            $row['TT'] = $row['TS'] + $row['TN'] + $row['TP'];
            $row['AT'] = $row['AS'] + $row['AN'] + $row['AP'];
            $row['GT'] = $row['TT'] + $row['AT'];
        }
        unset($row);

        ksort($aircraft);
        return $aircraft;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function findById(array $flights, string $flid): ?array
    {
        foreach ($flights as $flight) {
            if (isset($flight['flid']) && (string)$flight['flid'] === $flid) {
                return $flight;
            }
        }
        return null;
    }

    /**
     * Returns [schulung (bool/int), pax (bool/int)]
     */
    private function classifyFlight(int $ftid, string $attendantName): array
    {
        if (in_array($ftid, self::FTID_TRAINING, true)) {
            return [1, 0];
        }
        if ($ftid === self::FTID_PAX || $attendantName !== '') {
            return [0, 1];
        }
        return [0, 0];
    }

    private function cleanName(string $name): string
    {
        return str_replace(['\\', ','], ["'", ''], $name);
    }

    private function formatDate(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        // Input: YYYY-MM-DD  Output: D.M.YYYY
        $parts = explode('-', $raw);
        if (count($parts) !== 3) {
            return $raw;
        }
        return ltrim($parts[2], '0') . '.' . ltrim($parts[1], '0') . '.' . $parts[0];
    }

    /** Strip seconds from HH:MM:SS → HH:MM */
    private function trimSeconds(string $time): string
    {
        return strlen($time) === 8 ? substr($time, 0, 5) : $time;
    }
}
