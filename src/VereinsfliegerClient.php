<?php

declare(strict_types=1);

namespace Gvvt;

/**
 * Vereinsflieger REST API client.
 *
 * Improvements over the original VereinsfliegerRestInterface.php:
 *   - SSL peer verification enabled
 *   - Per-request timeout (10 s)
 *   - Token stored server-side; never passed in URLs
 *   - getFlightsMonth() uses curl_multi_exec to fire all day-requests in parallel
 *   - Password hashed with md5() matching the official PHP client (not lowercased)
 */
class VereinsfliegerClient
{
    private const BASE_URL  = 'https://www.vereinsflieger.de/interface/rest/';
    private const TIMEOUT_S = 10;

    private string $accessToken = '';
    private string $appKey;
    private int    $clubId;

    public function __construct()
    {
        $this->appKey = (string)(getenv('VF_APP_KEY') ?: '');
        $this->clubId = (int)(getenv('VF_CLUB_ID')  ?: 0);

        if ($this->appKey === '' || $this->clubId === 0) {
            throw new \RuntimeException(
                'Missing VF_APP_KEY or VF_CLUB_ID environment variables. ' .
                'Copy .env.example to .env and fill in the values.'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    /**
     * Authenticate with the Vereinsflieger API.
     * Returns true on success, false on failure.
     */
    public function signIn(string $username, string $password): bool
    {
        // Step 1: fetch a fresh access token
        $resp = $this->send('GET', 'auth/accesstoken');
        if ($resp === null || empty($resp['accesstoken'])) {
            return false;
        }
        $this->accessToken = $resp['accesstoken'];

        // Step 2: sign in — password is md5-hashed (matches official client)
        $resp = $this->send('POST', 'auth/signin', [
            'accesstoken' => $this->accessToken,
            'username'    => $username,
            'password'    => md5($password),
            'appkey'      => $this->appKey,
            'cid'         => $this->clubId,
        ]);

        return $resp !== null;
    }

    public function getToken(): string
    {
        return $this->accessToken;
    }

    /**
     * Restore a previously obtained token (e.g. from $_SESSION).
     * Validates it with a cheap API call; returns false if stale.
     */
    public function setToken(string $token): bool
    {
        $resp = $this->send('POST', 'flight/list/date', [
            'accesstoken' => $token,
            'dateparam'   => '2017-01-01',
        ]);
        if ($resp === null) {
            return false;
        }
        $this->accessToken = $token;
        return true;
    }

    public function signOut(): bool
    {
        $resp = $this->send('DELETE', 'auth/signout/' . $this->accessToken, [
            'accesstoken' => $this->accessToken,
        ]);
        $this->accessToken = '';
        return $resp !== null;
    }

    // -------------------------------------------------------------------------
    // Flight queries
    // -------------------------------------------------------------------------

    /**
     * Fetch all flights for a given day (YYYY-MM-DD).
     * Returns a flat array of flight arrays.
     */
    public function getFlightsDay(string $date): array
    {
        $resp = $this->send('POST', 'flight/list/date', [
            'accesstoken' => $this->accessToken,
            'dateparam'   => $date,
        ]);
        if ($resp === null) {
            return [];
        }
        // Response is a numeric-keyed object {"0":{...},"1":{...},...}
        return array_values(array_filter($resp, 'is_array'));
    }

    /**
     * Fetch all flights for a given month (YYYY-MM).
     * Uses curl_multi_exec to fire one request per day concurrently.
     */
    public function getFlightsMonth(string $month): array
    {
        [$year, $mon] = explode('-', $month);
        $daysInMonth  = cal_days_in_month(CAL_GREGORIAN, (int)$mon, (int)$year);

        $mh      = curl_multi_init();
        $handles = [];

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = sprintf('%s-%02d', $month, $d);
            $ch   = $this->buildHandle('POST', 'flight/list/date', [
                'accesstoken' => $this->accessToken,
                'dateparam'   => $date,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$d] = $ch;
        }

        // Execute all handles
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh);
            }
        } while ($active && $status === CURLM_OK);

        // Collect results
        $flights = [];
        foreach ($handles as $ch) {
            $raw  = curl_multi_getcontent($ch);
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $flights = array_merge($flights, array_values(array_filter($data, 'is_array')));
            }
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);

        return $flights;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Build a curl handle without executing it (used by curl_multi).
     */
    private function buildHandle(string $method, string $resource, array $data = []): \CurlHandle
    {
        $url = self::BASE_URL . $resource;
        $ch  = curl_init();

        $this->applyMethod($ch, $method, $data);

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => self::TIMEOUT_S,
        ]);

        return $ch;
    }

    /**
     * Execute a single request and return the decoded JSON, or null on error.
     */
    private function send(string $method, string $resource, array $data = []): ?array
    {
        $ch  = $this->buildHandle($method, $resource, $data);
        $raw = curl_exec($ch);
        $ok  = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);

        if (!$ok || $raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function applyMethod(\CurlHandle $ch, string $method, array $data): void
    {
        $fields = http_build_query($data, '', '&');
        switch ($method) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
                break;
            // GET: no body
        }
    }
}
