<?php

class AnkerSolixDriver implements DeviceDriverInterface
{
    private DriverConfig $dc;
    private ?string $lastAnkerError = null;

    private static $ankerApiServers = [
        'eu' => 'https://ankerpower-api-eu.anker.com',
        'com' => 'https://ankerpower-api.anker.com',
    ];

    private static $ankerEuCountries = [
        'DE', 'AT', 'CH', 'FR', 'IT', 'ES', 'PT', 'NL', 'BE', 'LU',
        'IE', 'GB', 'DK', 'SE', 'NO', 'FI', 'PL', 'CZ', 'SK', 'HU',
        'RO', 'BG', 'HR', 'SI', 'EE', 'LV', 'LT', 'GR', 'CY', 'MT',
    ];

    private static $ankerServerPublicKeyHex = '04c5c00c4f8d1197cc7c3167c52bf7acb054d722f0ef08dcd7e0883236e0d72a3868d9750cb47fa4619248f3d83f0f662671dadc6e2d31c2f41db0161651c7c076';

    public function __construct(DriverConfig $dc)
    {
        $this->dc = $dc;
    }

    public function getDeviceType(): string
    {
        return 'anker_solix';
    }

    private function getAnkerTokenCachePath(): string
    {
        $dir = $this->dc->log_file_dir;
        // Resolve relative paths from project root (handles API subdirectory context)
        if ($dir && $dir[0] !== '/') {
            $dir = dirname(__DIR__, 2) . '/' . $dir;
        }
        return $dir . 'anker_token.json';
    }

    public function getStats(): array
    {
        if (!function_exists('openssl_pkey_new')) {
            return ['error', 'PHP openssl extension is required for Anker Solix. Try <code>sudo apt install php-openssl</code>.'];
        }

        $email = $this->dc->anker_email;
        $password = $this->dc->anker_password;
        $country = $this->dc->anker_country ?: 'DE';

        if (!$email || !$password) {
            return ['error', 'Anker email and password must be configured. Go to <a href="overview.php">stats history</a>.'];
        }

        $region = in_array(strtoupper($country), self::$ankerEuCountries) ? 'eu' : 'com';
        $apiBase = self::$ankerApiServers[$region];

        $token = $this->loadAnkerToken();
        if (!$token) {
            $this->lastAnkerError = null;
            $token = $this->ankerLogin($apiBase, $email, $password, $country);
            if (!$token) {
                $detail = $this->lastAnkerError ? ' API: ' . htmlspecialchars($this->lastAnkerError) : '';
                return ['error', 'Anker login failed.' . $detail . ' Please check email, password and country configuration. Go to <a href="overview.php">stats history</a>.'];
            }
        }

        $siteId = $this->dc->anker_site_id;
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

        return $this->ankerGetEnergyAnalysis($apiBase, $token, $siteId, $deviceSn);
    }

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
        $batterySoc = intval($solarbank['battery_power'] ?? 0);
        $pvPower = intval($solarbank['photovoltaic_power'] ?? 0);
        $chargePower = intval($solarbank['bat_charge_power'] ?? 0);

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

    private function ankerLogin(string $apiBase, string $email, string $password, string $country): ?array
    {
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

        $x = str_pad($keyDetails['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($keyDetails['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $publicKeyUncompressed = "\x04" . $x . $y;
        $publicKeyHex = bin2hex($publicKeyUncompressed);

        $serverPubKeyBin = hex2bin(self::$ankerServerPublicKeyHex);
        $sharedKey = $this->ecdhDeriveSharedKey($privateKey, $serverPubKeyBin);
        if (!$sharedKey) {
            return null;
        }

        $iv = substr($sharedKey, 0, 16);
        $encryptedPassword = openssl_encrypt($password, 'aes-256-cbc', $sharedKey, OPENSSL_RAW_DATA, $iv);
        if ($encryptedPassword === false) {
            return null;
        }
        $encryptedPasswordB64 = base64_encode($encryptedPassword);

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

        $this->saveAnkerToken($tokenData);

        return $tokenData;
    }

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

    private function ankerHttpPost(string $url, array $body, array $extraHeaders): ?array
    {
        $headerLines = "Content-Type: application/json\r\n"
            . "Model-Type: DESKTOP\r\n"
            . "App-Name: anker_power\r\n"
            . "Os-Type: android\r\n";
        foreach ($extraHeaders as $key => $value) {
            $headerLines .= "{$key}: {$value}\r\n";
        }

        $jsonBody = json_encode($body ?: new \stdClass());

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => $headerLines,
                'content' => $jsonBody,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];
        $context = stream_context_create($opts);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            return null;
        }

        // Check HTTP status from response headers
        $httpCode = 0;
        if (isset($http_response_header[0])) {
            preg_match('/\d{3}/', $http_response_header[0], $matches);
            $httpCode = (int) ($matches[0] ?? 0);
        }

        if ($httpCode >= 500) {
            return null;
        }

        return json_decode($result, true);
    }

    private function ecdhDeriveSharedKey($privateKey, string $serverPubKeyBin): ?string
    {
        $serverPubKeyPem = $this->ecPointToPem($serverPubKeyBin);
        if (!$serverPubKeyPem) {
            return null;
        }

        $serverKey = openssl_pkey_get_public($serverPubKeyPem);
        if (!$serverKey) {
            return null;
        }

        if (function_exists('openssl_pkey_derive')) {
            $sharedSecret = openssl_pkey_derive($serverKey, $privateKey);
            if ($sharedSecret === false) {
                return null;
            }
            return str_pad(substr($sharedSecret, 0, 32), 32, "\0");
        }

        return null;
    }

    private function ecPointToPem(string $pointBin): ?string
    {
        $oid_ec = hex2bin('06072a8648ce3d0201');
        $oid_curve = hex2bin('06082a8648ce3d030107');
        $algoSeq = chr(0x30) . chr(strlen($oid_ec . $oid_curve)) . $oid_ec . $oid_curve;

        $bitString = chr(0x03) . chr(strlen($pointBin) + 1) . chr(0x00) . $pointBin;

        $der = chr(0x30) . chr(strlen($algoSeq . $bitString)) . $algoSeq . $bitString;

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        return $pem;
    }

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
        if (isset($data['expires_at']) && $data['expires_at'] < time() + 300) {
            @unlink($path);
            return null;
        }
        return $data;
    }

    private function saveAnkerToken(array $tokenData): void
    {
        $path = $this->getAnkerTokenCachePath();
        file_put_contents($path, json_encode($tokenData));
    }

    private function pmRound($value, bool $numberFormat = false, int $maxPrecision = 9)
    {
        return Helpers::round($value, $numberFormat, $maxPrecision, $this->dc->rounding_precision, $this->dc->power_threshold);
    }
}

//EOF
