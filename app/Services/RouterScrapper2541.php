<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Exception;

class RouterScrapper2541
{
    private $client;
    private $cookieJar;
    private $ip;
    private $password;
    private $result;

    public function __construct($ip, $username, $password, $modelo = 'Router')
    {
        $this->ip = $ip;
        $this->password = $password;

        // Preparar resultado base
        $this->result = [
            'ip' => $ip,
            'modelo' => $modelo,
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => 'unknown',
            'data' => []
        ];

        // CookieJar para mantener las cookies de sesión
        $this->cookieJar = new CookieJar();

        // Crear el cliente HTTP
        $this->client = new Client([
            'base_uri' => "http://{$ip}",
            'timeout' => 15,
            'cookies' => $this->cookieJar,
            'verify' => false,
            'http_errors' => false,
            'allow_redirects' => true,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Referer' => "http://{$ip}"
            ]
        ]);
    }

    /**
     * Ejecuta el scraping del router modelo 2541 (MitraStar GPT-2541GNAC)
     */
    public function scrape()
    {
        try {
            // PASO 1: Obtener página de login
            // (Esta es una estructura base, los paths y selectores se ajustarán)
            $loginPageResponse = $this->client->get('/cgi-bin/logIn_mhs.cgi');
            $loginPageHtml = (string) $loginPageResponse->getBody();

            // Extraer el SID: var sid = 'XXXXXXXX';
            if (preg_match("/var\s+sid\s*=\s*['\"]([a-f0-9]+)['\"]/i", $loginPageHtml, $matches)) {
                $sid = $matches[1];
                $this->result['data']['sid'] = $sid;

                // PASO 2: Login
                $encryptedPassword = md5($this->password . ":" . $sid);

                $this->client->post('/cgi-bin/logIn_mhs.cgi', [
                    'form_params' => [
                        'submitValue' => '1',
                        'syspasswd'   => $encryptedPassword,
                        'leaveBlur'   => '0'
                    ]
                ]);

                // PASO 3: Extracción de WiFi 2.4G
                $wifiResponse = $this->client->get('/cgi-bin/mhs.cgi');
                $wifiHtml = (string) $wifiResponse->getBody();
                
                // Guardar para debug inicial
                file_put_contents(storage_path('logs/router_2541_debug_24g.html'), $wifiHtml);

                if (strpos($wifiHtml, 'SSID') !== false || strpos($wifiHtml, 'ssidname') !== false) {
                    $this->result['data']['ssid'] = $this->extractInputValue($wifiHtml, 'SSID');
                    $this->result['data']['wifi_password'] = $this->extractInputValue($wifiHtml, 'WPAPSK_Key');
                    $this->result['data']['current_channel'] = $this->extractJSVariable($wifiHtml, 'CurrentChannel');
                }

                // PASO 4: Extracción de WiFi 5G (WiFi Plus)
                $wifi5gResponse = $this->client->get('/cgi-bin/wifi5g.cgi');
                $wifi5gHtml = (string) $wifi5gResponse->getBody();
                
                // Guardar para debug inicial
                file_put_contents(storage_path('logs/router_2541_debug_5g.html'), $wifi5gHtml);

                if (strpos($wifi5gHtml, 'SSID') !== false || strpos($wifi5gHtml, 'ssidname') !== false) {
                    $this->result['data']['ssid_5g'] = $this->extractInputValue($wifi5gHtml, 'SSID');
                    $this->result['data']['wifi_password_5g'] = $this->extractInputValue($wifi5gHtml, 'WPAPSK_Key');
                    $this->result['data']['current_channel_5g'] = $this->extractJSVariable($wifi5gHtml, 'CurrentChannel');
                }

                // PASO 5: Página de Instalación (ONT/SLID/MAC)
                if ($this->loginInstallation()) {
                    $ontResponse = $this->client->get('/cgi-bin/Instalacion_ontpw.cgi');
                    $ontHtml = (string) $ontResponse->getBody();

                    // Guardar para debug inicial
                    file_put_contents(storage_path('logs/router_2541_debug_inst.html'), $ontHtml);

                    // MAC
                    if (preg_match('/Direcciones MAC:.*?<br>\s*([A-F0-9:]+)/is', $ontHtml, $m)) {
                        $this->result['data']['mac_address'] = trim($m[1]);
                        $this->result['data']['numero_serie'] = trim($m[1]);
                    }

                    // SLID
                    $slidHex = $this->extractJSVariable($ontHtml, 'SLIDHexValue');
                    if ($slidHex !== '') {
                        $this->result['data']['gpon_password'] = $this->hex2ascii($slidHex);
                    }
                }
            }

            $this->result['status'] = 'success';

        } catch (Exception $e) {
            $this->result['status'] = 'error';
            $this->result['error'] = $e->getMessage();
        }

        return $this->result;
    }

    /**
     * Configura el identificador GPON (ONT ID) en el router
     */
    public function configGpon($gpon)
    {
        try {
            if (!$this->loginInstallation()) {
                throw new Exception("No se pudo entrar a la página de instalación.");
            }

            $slidHex = $this->ascii2hex($gpon);

            $response = $this->client->post('/cgi-bin/Instalacion_ontpw.cgi', [
                'form_params' => [
                    'SLID'        => $slidHex,
                    'submitValue' => '1',
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                return [
                    'status' => 'success',
                    'message' => 'GPON configurado correctamente.'
                ];
            }

            throw new Exception("Error al enviar la configuración GPON.");

        } catch (Exception $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * Resetea el identificador GPON (ONT ID) del router a 000000
     */
    public function resetConfigGpon()
    {
        return $this->configGpon('000000');
    }

    /**
     * Login en el menú de Instalador
     */
    private function loginInstallation(): bool
    {
        $response = $this->client->get('/cgi-bin/logIn_Instalacion.cgi');
        $html = (string) $response->getBody();

        if (preg_match("/var\s+sid\s*=\s*['\"]([a-f0-9]+)['\"]/i", $html, $matches)) {
            $sid = $matches[1];
            $encryptedPass = md5($this->password . ":" . $sid);

            $this->client->post('/cgi-bin/logIn_Instalacion.cgi', [
                'form_params' => [
                    'sysusername' => 'Support',
                    'syspasswd'   => $encryptedPass,
                    'submitValue' => '1',
                    'leaveBlur'   => '0'
                ]
            ]);
            return true;
        }
        return false;
    }

    /**
     * Helpers de extracción y conversión
     */
    private function extractInputValue(string $html, string $fieldId): string
    {
        $escaped = preg_quote($fieldId, '/');
        if (preg_match('/<input[^>]*(?:id|name)=["\']' . $escaped . '["\'][^>]*value=["\']([^"\']*)["\']/', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/<input[^>]*value=["\']([^"\']*)["\'][^>]*(?:id|name)=["\']' . $escaped . '["\']/', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    private function extractJSVariable(string $html, string $variableName): string
    {
        $escaped = preg_quote($variableName, '/');
        $pattern = '/(?:var\s+)?' . $escaped . '\s*=\s*[\'"]([^\'"]+)[\'"]\s*;?/i';
        if (preg_match($pattern, $html, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function hex2ascii(string $hex): string
    {
        $str = '';
        for ($i = 0; $i < strlen($hex); $i += 2) {
            $char = chr(hexdec(substr($hex, $i, 2)));
            if ($char !== "\x00") $str .= $char;
        }
        return trim($str);
    }

    private function ascii2hex(string $ascii): string
    {
        $hex = '';
        for ($i = 0; $i < strlen($ascii); $i++) {
            $h = dechex(ord($ascii[$i]));
            $hex .= (strlen($h) < 2 ? '0' . $h : $h);
        }
        return str_pad(strtoupper($hex), 20, '0', STR_PAD_RIGHT);
    }
}
