<?php

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Helpers.php';

class DeviceDriver
{
    private $config;
    private ?string $lastAnkerError = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Query the configured device and return stats array.
     *
     * @return array Stats array with keys: date, time, power, [temp], [emeters]
     *               Or error array: ['error', 'message']
     */
    public function getStats(): array
    {
        $device = $this->config->device;
        $method = 'getStats' . str_replace(['-', '_'], '', ucwords($device, '-_'));

        if (method_exists($this, $method)) {
            return $this->$method();
        }

        return ['error', 'Invalid device configured.'];
    }

    private function pmRound($value, bool $numberFormat = false, int $maxPrecision = 9)
    {
        return Helpers::round(
            $value,
            $numberFormat,
            $maxPrecision,
            $this->config->rounding_precision,
            $this->config->power_threshold
        );
    }

    private function getStatsFritzbox(): array
    {
        if (!function_exists('mb_convert_encoding')) {
            return ['error', 'PHP function "mb_convert_encoding" does not exist! Try <code>sudo apt update && sudo apt install -y php-mbstring</code> to install.'];
        }

        $host = $this->config->host;
        $user = $this->config->user;
        $pass = $this->config->pass;
        $ain = $this->config->ain;

        $sid = $this->getFritzBoxSessionId($host, $user, $pass);

        $time = time();
        $stats = @file_get_contents("http://{$host}/webservices/homeautoswitch.lua?ain={$ain}&switchcmd=getbasicdevicestats&sid={$sid}");
        $stats_array = [];

        if ($stats) {
            $stats_array['date'] = date('d.m.Y', $time);
            $stats_array['time'] = date('H:i:s', $time);

            if (!preg_match('/<voltage><stats count="[0-9]+" grid="[0-9]+"(?: datatime="[0-9]+")?>([0-9]+),/', $stats)) {
                return ['error', 'FRITZ!DECT seems to be offline, please check.'];
            }

            preg_match('/<power><stats count="[0-9]+" grid="[0-9]+"(?: datatime="[0-9]+")?>([0-9]+),/', $stats, $match);
            $stats_array['power'] = $this->pmRound($match[1] / 100, true, 2);

            preg_match('/<temperature><stats count="[0-9]+" grid="[0-9]+"(?: datatime="[0-9]+")?>([\-0-9]+),/', $stats, $match);
            $stats_array['temp'] = $this->pmRound($match[1] / 10, true, 1);

            return $stats_array;
        }

        return ['error', 'Unable to get stats. Please check host, username, password and ain configuration. Go to <a href="overview.php">stats history</a>.'];
    }

    private function getFritzBoxSessionId(string $host, string $user, string $pass): string
    {
        $text = @file_get_contents("http://{$host}/login_sid.lua");
        preg_match('/<SID>(.*)<\/SID>/', $text, $match);
        $sid = $match[1];

        if ($sid == '0000000000000000') {
            preg_match('/<Challenge>(.*)<\/Challenge>/', $text, $match);
            $challenge = $match[1];
            $response = $challenge . '-' . md5(mb_convert_encoding($challenge . '-' . $pass, 'UTF-16LE', 'UTF-8'));
            $text = @file_get_contents("http://{$host}/login_sid.lua?username={$user}&response={$response}");
            preg_match('/<SID>(.*)<\/SID>/', $text, $match);
            $sid = $match[1];
        }

        return $sid;
    }

    private function getStatsTasmota(): array
    {
        $host = $this->config->host;
        $obj = @json_decode(@file_get_contents("http://{$host}/cm?cmnd=Status%208"));

        if (is_object($obj) && is_int($obj->StatusSNS->ENERGY->Power)) {
            $time = strtotime($obj->StatusSNS->Time);
            if ($time < 500000000) {
                $time = time();
            }
            return [
                'date' => date('d.m.Y', $time),
                'time' => date('H:i:s', $time),
                'power' => $this->pmRound($obj->StatusSNS->ENERGY->Voltage * $obj->StatusSNS->ENERGY->Current * $obj->StatusSNS->ENERGY->Factor, true, 3),
            ];
        }

        return ['error', 'Unable to get stats. Please check host configuration and if the device is powered. Go to <a href="overview.php">stats history</a>.'];
    }

    private function getStatsShelly3em(): array
    {
        $host = $this->config->host;
        $data = @json_decode(@file_get_contents("http://{$host}/status"), true);

        if ($data) {
            $time = $data['unixtime'];
            if ($time < 500000000) {
                $time = time();
            }
            $stats_array = [
                'date' => date('d.m.Y', $time),
                'time' => date('H:i:s', $time),
                'power' => $this->pmRound($data['total_power'], true, 2),
                'temp' => '',
            ];
            foreach ($data['emeters'] as $emeter) {
                $stats_array['emeters'][] = $emeter['power'];
            }
            return $stats_array;
        }

        return ['error', 'Unable to get stats. Please check host configuration and if the device is powered. Go to <a href="overview.php">stats history</a>.'];
    }

    private function getStatsShellyGen2(): array
    {
        $host = $this->config->host;
        $data = @json_decode(@file_get_contents("http://{$host}/rpc/Shelly.GetStatus"), true);

        if (!$data) {
            return ['error', 'Unable to query Shelly device. Go to <a href="overview.php">stats history</a>.'];
        }

        $power = $data['switch:0']['apower'];
        $time = $data['sys']['unixtime'];

        if (!isset($time)) {
            return ['error', 'Unable to get stats. Please check host configuration and if the device is powered. Go to <a href="overview.php">stats history</a>.'];
        }

        if ($time < 500000000) {
            $time = time();
        }

        $stats_array = [
            'date' => date('d.m.Y', $time),
            'time' => date('H:i:s', $time),
            'power' => $this->pmRound($power, true, 2),
        ];

        if (isset($data['switch:0']['temperature']['tC'])) {
            $stats_array['temp'] = $this->pmRound($data['switch:0']['temperature']['tC'], true, 2);
        }

        return $stats_array;
    }

    private function getStatsShelly(): array
    {
        $host = $this->config->host;
        $data = @json_decode(@file_get_contents("http://{$host}/status"), true);

        if (!$data) {
            return ['error', 'Unable to query Shelly device. Go to <a href="overview.php">stats history</a>.'];
        }

        $power = 0;
        $time = null;
        foreach ($data['meters'] as $meter) {
            if ($meter['is_valid']) {
                $power += $meter['power'];
                $time = $meter['timestamp'];
            }
        }

        if (!isset($time)) {
            return ['error', 'Unable to get stats. Please check host configuration and if the device is powered. Go to <a href="overview.php">stats history</a>.'];
        }

        if ($time < 500000000) {
            $time = time();
        }

        $stats_array = [
            'date' => DateTime::createFromFormat('U', $time)->format('d.m.Y'),
            'time' => DateTime::createFromFormat('U', $time)->format('H:i:s'),
            'power' => $this->pmRound($power, true, 2),
        ];

        if (isset($data['temperature'])) {
            $stats_array['temp'] = $this->pmRound($data['temperature'], true, 2);
        }

        return $stats_array;
    }

    private function getStatsEnvtec(): array
    {
        $stationId = $this->config->station_id;
        $opts = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: 0\r\n"]];
        $context = stream_context_create($opts);
        $url = "https://www.envertecportal.com/ApiInverters/QueryTerminalReal?page=1&perPage=20&orderBy=GATEWAYSN&whereCondition=" . urlencode('{"STATIONID":"' . $stationId . '"}');
        $result = @file_get_contents($url, false, $context);

        if (!$result) {
            return ['error', 'Unable to query envertecportal.com. Go to <a href="overview.php">stats history</a>.'];
        }

        $data = json_decode($result, true);

        if (!$data['Data']['QueryResults']) {
            return ['error', 'Unable to get stats. Please check station ID configuration. Go to <a href="overview.php">stats history</a>.'];
        }

        $data_timestamps = [];
        foreach ($data['Data']['QueryResults'] as $result) {
            $data_timestamps[] = $result['SITETIME'];
        }
        $stats_timestamp = max($data_timestamps);

        $skipped = 0;
        $stats_power = [];
        $stats_temp = [];
        foreach ($data['Data']['QueryResults'] as $result) {
            if (!$result['SITETIME']) {
                continue;
            }
            if ($result['SITETIME'] != $stats_timestamp) {
                $skipped++;
            } else {
                $stats_power[] = $result['POWER'];
                $stats_temp[] = $result['TEMPERATURE'];
            }
        }

        $timeZone = new DateTimeZone('Europe/Helsinki');
        $dateTime = DateTime::createFromFormat('m/d/Y h:i:s A', $stats_timestamp, $timeZone);
        $berlinTz = new DateTimeZone('Europe/Berlin');
        $stats_array = [
            'date' => $dateTime->setTimezone($berlinTz)->format('d.m.Y'),
            'time' => $dateTime->setTimezone($berlinTz)->format('H:i:s'),
            'power' => array_sum($stats_power),
            'temp' => $this->pmRound(array_sum($stats_temp) / count($stats_temp), true, 1),
        ];

        if ($skipped) {
            $i = count($data['Data']['QueryResults']);
            $stats_array['power'] = $stats_array['power'] / $i * ($i + $skipped);
        }
        $stats_array['power'] = $this->pmRound($stats_array['power'], true, 2);

        return $stats_array;
    }

    private function getStatsAhoydtu(): array
    {
        $host = $this->config->host;
        $inverterId = $this->config->inverter_id;
        $data = @json_decode(@file_get_contents("http://{$host}/api/inverter/id/{$inverterId}"));

        if (is_object($data) && $data->ts_last_success) {
            $time = $data->ts_last_success;
            $stats_array = [
                'date' => date('d.m.Y', $time),
                'time' => date('H:i:s', $time),
                'power' => $this->pmRound($data->ch[0][2], true, 1),
                'temp' => $this->pmRound($data->ch[0][5], true, 1),
            ];

            if (array_key_exists(2, $data->ch)) {
                $stats_array['emeters'][] = $this->pmRound($data->ch[1][2], true, 1);
                $stats_array['emeters'][] = $this->pmRound($data->ch[2][2], true, 1);
                if (array_key_exists(4, $data->ch)) {
                    $stats_array['emeters'][] = $this->pmRound($data->ch[3][2], true, 1);
                    $stats_array['emeters'][] = $this->pmRound($data->ch[4][2], true, 1);
                }
            } else {
                $stats_array['emeters'][] = $this->pmRound($data->ch[1][0], true, 1);
                $stats_array['emeters'][] = $this->pmRound($data->ch[1][1], true, 2);
            }

            return $stats_array;
        }

        return ['error', 'Unable to get stats. Please check host configuration and if the device is powered. Go to <a href="overview.php">stats history</a>.'];
    }

    private function getStatsEspepevercontroller(): array
    {
        $host = $this->config->host;
        $time = time();
        $data = @json_decode(@file_get_contents("http://{$host}/AllJsonData", false, stream_context_create(['http' => ['timeout' => 1]])));

        if (is_object($data) && $data->BatteryV) {
            return [
                'date' => date('d.m.Y', $time),
                'time' => date('H:i:s', $time),
                'power' => $this->pmRound($data->PanelP, true, 2),
                'temp' => $this->pmRound($data->BatteryV, true, 2),
                'emeters' => [
                    $this->pmRound($data->BatteryI, true, 2),
                    $this->pmRound($data->PanelV, true, 2),
                    $this->pmRound($data->PanelI, true, 2),
                ],
            ];
        }

        return ['error', 'Unable to get stats. Please check host configuration and if the device is powered. Go to <a href="overview.php">stats history</a>.'];
    }

    // --- Anker Solix E1600 ---

    /**
     * Anker Cloud API servers by region.
     */
    private static $ankerApiServers = [
        'eu' => 'https://ankerpower-api-eu.anker.com',
        'com' => 'https://ankerpower-api.anker.com',
    ];

    /**
     * EU countries mapped to 'eu' server, everything else to 'com'.
     */
    private static $ankerEuCountries = [
        'DE', 'AT', 'CH', 'FR', 'IT', 'ES', 'PT', 'NL', 'BE', 'LU',
        'IE', 'GB', 'DK', 'SE', 'NO', 'FI', 'PL', 'CZ', 'SK', 'HU',
        'RO', 'BG', 'HR', 'SI', 'EE', 'LV', 'LT', 'GR', 'CY', 'MT',
    ];

    /**
     * Hardcoded Anker server public key (uncompressed EC point, SECP256R1).
     */
    private static $ankerServerPublicKeyHex = '04c5c00c4f8d1197cc7c3167c52bf7acb054d722f0ef08dcd7e0883236e0d72a3868d9750cb47fa4619248f3d83f0f662671dadc6e2d31c2f41db0161651c7c076';

    /**
     * Cached auth token (persisted in data dir to avoid re-login on every call).
     */
    private function getAnkerTokenCachePath(): string
    {
        return $this->config->log_file_dir . 'anker_token.json';
    }

    private function getStatsAnkerSolix(): array
    {
        if (!function_exists('openssl_pkey_new')) {
            return ['error', 'PHP openssl extension is required for Anker Solix. Try <code>sudo apt install php-openssl</code>.'];
        }

        $email = $this->config->anker_email;
        $password = $this->config->anker_password;
        $country = $this->config->anker_country ?: 'DE';

        if (!$email || !$password) {
            return ['error', 'Anker email and password must be configured. Go to <a href="overview.php">stats history</a>.'];
        }

        // Determine API server based on country
        $region = in_array(strtoupper($country), self::$ankerEuCountries) ? 'eu' : 'com';
        $apiBase = self::$ankerApiServers[$region];

        // Try cached token first
        $token = $this->loadAnkerToken();
        if (!$token) {
            $this->lastAnkerError = null;
            $token = $this->ankerLogin($apiBase, $email, $password, $country);
            if (!$token) {
                $detail = $this->lastAnkerError ? ' API: ' . htmlspecialchars($this->lastAnkerError) : '';
                return ['error', 'Anker login failed.' . $detail . ' Please check email, password and country configuration. Go to <a href="overview.php">stats history</a>.'];
            }
        }

        // Resolve site_id and device info
        $siteId = $this->config->anker_site_id;
        $deviceSn = '';
        $productCode = '';
        $siteData = $this->ankerApiRequest($apiBase, $token, 'power_service/v1/site/get_site_list');
        if ($siteData === null) {
            $token = $this->ankerLogin($apiBase, $email, $password, $country);
            if (!$token) {
                return ['error', 'Anker re-login failed. Go to <a href="overview.php">stats history</a>.'];
            }
            $siteData = $this->ankerApiRequest($apiBase, $token, 'power_service/v1/site/get_site_list');
        }
        if ($siteData && isset($siteData['site_list'][0])) {
            if (!$siteId) {
                $siteId = $siteData['site_list'][0]['site_id'];
            }
            if (isset($siteData['site_list'][0]['site_device_list'][0])) {
                $dev = $siteData['site_list'][0]['site_device_list'][0];
                $deviceSn = $dev['device_sn'];
                $productCode = $dev['device_model'] ?? '';
            }
        }

        if (!$siteId) {
            return ['error', 'No Anker Solix site found. Go to <a href="overview.php">stats history</a>.'];
        }

        // Try scene_info for real-time data (same endpoint as the Anker app home screen)
        $sceneData = $this->ankerGetSceneInfo($apiBase, $token, $siteId, $deviceSn);
        if ($sceneData) {
            $time = time();
            return [
                'date' => date('d.m.Y', $time),
                'time' => date('H:i:s', $time),
                'power' => $this->pmRound($sceneData['output_power'] ?? 0, true, 0),
                'temp' => $this->pmRound($sceneData['battery_soc'] ?? 0, true, 0),
                'emeters' => [
                    $this->pmRound($sceneData['pv_power'] ?? 0, true, 0),
                    $this->pmRound($sceneData['charge_power'] ?? 0, true, 0),
                ],
            ];
        }

        // Fallback: energy_analysis API (20-min delayed data)
        return $this->ankerGetEnergyAnalysis($apiBase, $token, $siteId, $deviceSn);
    }

    /**
     * Fallback: get stats from energy_analysis endpoint (20-min resolution).
     */
    private function ankerGetEnergyAnalysis(string $apiBase, array $token, string $siteId, string $deviceSn): array
    {
        $today = date('Y-m-d');
        $energyData = $this->ankerApiRequest($apiBase, $token, 'power_service/v1/site/energy_analysis', [
            'site_id' => $siteId,
            'device_sn' => $deviceSn,
            'type' => 'day',
            'start_time' => $today,
            'end_time' => $today,
            'device_type' => 'solar_production',
        ]);

        if (!$energyData) {
            return ['error', 'Unable to query Anker energy data. Go to <a href="overview.php">stats history</a>.'];
        }

        $currentPower = 0;
        if (isset($energyData['power']) && is_array($energyData['power'])) {
            $readings = $energyData['power'];
            for ($i = count($readings) - 1; $i >= 0; $i--) {
                if (floatval($readings[$i]['value']) > 0) {
                    $currentPower = floatval($readings[$i]['value']);
                    break;
                }
            }
        }

        $solarTotal = isset($energyData['solar_total']) ? floatval($energyData['solar_total']) : 0;
        $chargeTotal = isset($energyData['charge_total']) ? floatval($energyData['charge_total']) : 0;
        $dischargeTotal = isset($energyData['discharge_total']) ? floatval($energyData['discharge_total']) : 0;

        $time = time();
        return [
            'date' => date('d.m.Y', $time),
            'time' => date('H:i:s', $time),
            'power' => $this->pmRound($currentPower, true, 1),
            'temp' => $this->pmRound($solarTotal * 1000, true, 0),
            'emeters' => [
                $this->pmRound($chargeTotal * 1000, true, 0),
                $this->pmRound($dischargeTotal * 1000, true, 0),
            ],
        ];
    }

    /**
     * Get real-time data from scene_info endpoint (Anker app home screen data).
     * Returns output_power, battery_soc (%), photovoltaic_power, charging_power.
     */
    private function ankerGetSceneInfo(string $apiBase, array $token, string $siteId, string $deviceSn): ?array
    {
        $data = $this->ankerApiRequest($apiBase, $token, 'power_service/v1/site/get_scen_info', [
            'site_id' => $siteId,
        ]);

        if (!$data || !isset($data['solarbank_info']['solarbank_list'])) {
            return null;
        }

        $sbList = $data['solarbank_info']['solarbank_list'];
        if (empty($sbList)) {
            return null;
        }

        // Find the matching device, or use the first one
        $solarbank = $sbList[0];
        if ($deviceSn) {
            foreach ($sbList as $sb) {
                if (isset($sb['device_sn']) && $sb['device_sn'] === $deviceSn) {
                    $solarbank = $sb;
                    break;
                }
            }
        }

        $outputPower = intval($solarbank['output_power'] ?? 0);
        $batterySoc = intval($solarbank['battery_power'] ?? 0); // "battery_power" is actually SOC %
        $pvPower = intval($solarbank['photovoltaic_power'] ?? 0);
        $chargePower = intval($solarbank['bat_charge_power'] ?? 0);

        // Only return if we got meaningful data
        if ($outputPower == 0 && $batterySoc == 0 && $pvPower == 0) {
            return null;
        }

        return [
            'output_power' => $outputPower,
            'battery_soc' => $batterySoc,
            'pv_power' => $pvPower,
            'charge_power' => $chargePower,
        ];
    }

    /**
     * Authenticate with Anker Cloud API using ECDH + AES-256-CBC.
     *
     * @return array|null Token data [auth_token, user_id, gtoken, expires_at] or null on failure
     */
    private function ankerLogin(string $apiBase, string $email, string $password, string $country): ?array
    {
        // Generate ECDH key pair (SECP256R1 / prime256v1)
        $privateKey = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if (!$privateKey) {
            return null;
        }

        $keyDetails = openssl_pkey_get_details($privateKey);
        if (!$keyDetails || $keyDetails['type'] !== OPENSSL_KEYTYPE_EC) {
            return null;
        }

        // Build uncompressed public key: 04 + x + y (each 32 bytes, zero-padded)
        $x = str_pad($keyDetails['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($keyDetails['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $publicKeyUncompressed = "\x04" . $x . $y;
        $publicKeyHex = bin2hex($publicKeyUncompressed);

        // Derive shared secret using server's public key
        $serverPubKeyBin = hex2bin(self::$ankerServerPublicKeyHex);
        $sharedKey = $this->ecdhDeriveSharedKey($privateKey, $serverPubKeyBin);
        if (!$sharedKey) {
            return null;
        }

        // Encrypt password: AES-256-CBC, key=shared_key(32 bytes), iv=shared_key[:16]
        $iv = substr($sharedKey, 0, 16);
        $encryptedPassword = openssl_encrypt($password, 'aes-256-cbc', $sharedKey, OPENSSL_RAW_DATA, $iv);
        if ($encryptedPassword === false) {
            return null;
        }
        $encryptedPasswordB64 = base64_encode($encryptedPassword);

        // Build login request
        $now = new DateTime();
        $tzOffsetMs = (int) $now->getOffset() * 1000;
        $transactionMs = (string) round(microtime(true) * 1000);

        $body = [
            'ab' => strtoupper($country),
            'client_secret_info' => [
                'public_key' => $publicKeyHex,
            ],
            'enc' => 0,
            'email' => $email,
            'password' => $encryptedPasswordB64,
            'time_zone' => $tzOffsetMs,
            'transaction' => $transactionMs,
        ];

        $response = $this->ankerHttpPost($apiBase . '/' . 'passport/login', $body, []);

        if (!$response || !isset($response['data']['auth_token'])) {
            $this->lastAnkerError = $response['msg'] ?? null;
            return null;
        }

        $tokenData = [
            'auth_token' => $response['data']['auth_token'],
            'user_id' => $response['data']['user_id'],
            'gtoken' => md5($response['data']['user_id']),
            'expires_at' => $response['data']['token_expires_at'] ?? (time() + 86400),
        ];

        // Cache token
        $this->saveAnkerToken($tokenData);

        return $tokenData;
    }

    /**
     * Make an authenticated API request to Anker Cloud.
     *
     * @return array|null Parsed response data or null on failure
     */
    private function ankerApiRequest(string $apiBase, array $token, string $endpoint, array $body = []): ?array
    {
        $headers = [
            'gtoken' => $token['gtoken'],
            'x-auth-token' => $token['auth_token'],
        ];

        $response = $this->ankerHttpPost($apiBase . '/' . $endpoint, $body, $headers);

        if (!$response || (isset($response['code']) && $response['code'] != 0)) {
            return null;
        }

        return $response['data'] ?? null;
    }

    /**
     * Send an HTTP POST request with JSON body.
     */
    private function ankerHttpPost(string $url, array $body, array $extraHeaders): ?array
    {
        $headers = [
            'Content-Type: application/json',
            'Model-Type: DESKTOP',
            'App-Name: anker_power',
            'Os-Type: android',
        ];
        foreach ($extraHeaders as $key => $value) {
            $headers[] = "{$key}: {$value}";
        }

        $jsonBody = json_encode($body ?: new \stdClass());
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $httpCode >= 500) {
            return null;
        }

        return json_decode($result, true);
    }

    /**
     * Derive ECDH shared secret between our private key and the server's public key.
     *
     * @param resource $privateKey OpenSSL private key
     * @param string $serverPubKeyBin Server public key (uncompressed point, binary)
     * @return string|null 32-byte shared secret or null on failure
     */
    private function ecdhDeriveSharedKey($privateKey, string $serverPubKeyBin): ?string
    {
        // Build a PEM-encoded EC public key from the raw uncompressed point
        $serverPubKeyPem = $this->ecPointToPem($serverPubKeyBin);
        if (!$serverPubKeyPem) {
            return null;
        }

        $serverKey = openssl_pkey_get_public($serverPubKeyPem);
        if (!$serverKey) {
            return null;
        }

        // Use openssl_pkey_derive for ECDH (PHP 7.3+)
        if (function_exists('openssl_pkey_derive')) {
            $sharedSecret = openssl_pkey_derive($serverKey, $privateKey);
            if ($sharedSecret === false) {
                return null;
            }
            // Pad or truncate to 32 bytes
            return str_pad(substr($sharedSecret, 0, 32), 32, "\0");
        }

        return null;
    }

    /**
     * Convert a raw EC uncompressed point (04 + x + y) to PEM format.
     */
    private function ecPointToPem(string $pointBin): ?string
    {
        // EC public key DER structure for SECP256R1 (prime256v1)
        // SEQUENCE {
        //   SEQUENCE {
        //     OID 1.2.840.10045.2.1 (ecPublicKey)
        //     OID 1.2.840.10045.3.1.7 (prime256v1)
        //   }
        //   BIT STRING (uncompressed point)
        // }
        $oid_ec = hex2bin('06072a8648ce3d0201');       // OID ecPublicKey
        $oid_curve = hex2bin('06082a8648ce3d030107');   // OID prime256v1
        $algoSeq = chr(0x30) . chr(strlen($oid_ec . $oid_curve)) . $oid_ec . $oid_curve;

        $bitString = chr(0x03) . chr(strlen($pointBin) + 1) . chr(0x00) . $pointBin;

        $der = chr(0x30) . chr(strlen($algoSeq . $bitString)) . $algoSeq . $bitString;

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        return $pem;
    }

    /**
     * Load cached Anker auth token from file.
     */
    private function loadAnkerToken(): ?array
    {
        $path = $this->getAnkerTokenCachePath();
        if (!file_exists($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true);
        if (!$data || !isset($data['auth_token'])) {
            return null;
        }
        // Check expiry (with 5 minute buffer)
        if (isset($data['expires_at']) && $data['expires_at'] < time() + 300) {
            @unlink($path);
            return null;
        }
        return $data;
    }

    /**
     * Save Anker auth token to cache file.
     */
    private function saveAnkerToken(array $tokenData): void
    {
        $path = $this->getAnkerTokenCachePath();
        file_put_contents($path, json_encode($tokenData));
    }
}

//EOF
