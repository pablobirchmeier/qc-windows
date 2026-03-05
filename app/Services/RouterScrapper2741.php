<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Exception;

class RouterScrapper2741
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

        // Preparar resultado
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
     * Ejecuta el scraping del router modelo 2741
     */
    /**
     * Ejecuta el scraping del router modelo 2741 (MitraStar GPT-2741GNAC)
     */
    public function scrape()
    {
        try {
            // PASO 1: Obtener página de login para extraer el SID y la potencia óptica
            $loginPageResponse = $this->client->get('/cgi-bin/logIn_mhs.cgi');
            $loginPageHtml = (string) $loginPageResponse->getBody();

            $this->result['data']['html_preview'] = substr($loginPageHtml, 0, 500);

            // Extraer potencia óptica desde div#rxPower
            if (preg_match('/id=["\']rxPower["\'][^>]*>.*?([-\d.]+)\s*dBm/s', $loginPageHtml, $matches)) {
                $this->result['data']['potencia_optica'] = $matches[1] . ' dBm';
            } elseif (preg_match('/([-\d.]+)\s*dBm/', $loginPageHtml, $matches)) {
                $this->result['data']['potencia_optica'] = $matches[1] . ' dBm';
            }

            // Extraer el SID: var sid = 'XXXXXXXX';
            if (!preg_match("/var\s+sid\s*=\s*['\"]([a-f0-9]+)['\"]/i", $loginPageHtml, $matches)) {
                $this->result['data']['sid_search_debug'] = strpos($loginPageHtml, 'sid') !== false
                    ? 'Contiene "sid" pero no coincide con el patrón'
                    : 'No encontrado';
                throw new Exception("No se pudo obtener el SID desde la página de login");
            }
            $sid = $matches[1];
            $this->result['data']['sid'] = $sid;

            // PASO 2: Login — syspasswd = md5(password + ":" + sid)
            $encryptedPassword = md5($this->password . ":" . $sid);

            $loginResponse = $this->client->post('/cgi-bin/logIn_mhs.cgi', [
                'form_params' => [
                    'sessionKey'  => '',
                    'submitValue' => '1',
                    'syspasswd'   => $encryptedPassword,
                    'syspasswd_1' => '',
                    'leaveBlur'   => '0'
                ]
            ]);

            $this->result['data']['login_status_code'] = $loginResponse->getStatusCode();

            // PASO 3: Página WiFi 2.4GHz (/cgi-bin/mhs.cgi)
            $wifiResponse = $this->client->get('/cgi-bin/mhs.cgi');
            $wifiHtml = (string) $wifiResponse->getBody();

            // Guardar HTML para debug (ayuda al usuario a verificar si el scrapper "ve" lo mismo que el browser)
            $debugPath = storage_path('logs/router_2741_wifi_debug.html');
            file_put_contents($debugPath, $wifiHtml);
            $this->result['data']['debug_html_saved'] = $debugPath;

            // Verificamos si logramos entrar (buscando campos conocidos)
            $isAuthenticated = (strpos($wifiHtml, 'ssidname') !== false || strpos($wifiHtml, 'SSID') !== false);
            $this->result['data']['is_authenticated'] = $isAuthenticated ? 'yes' : 'no';

            if ($isAuthenticated) {
                // SSID 2.4GHz: Preferimos el campo oculto "SSID" que sí tiene el value en el HTML
                $ssid = $this->extractInputValue($wifiHtml, 'SSID') ?: $this->extractInputValue($wifiHtml, 'ssidname');
                if (!empty($ssid)) {
                    $this->result['data']['ssid'] = $ssid;
                }

                // Password WiFi 2.4GHz: Preferimos el campo oculto "WPAPSK_Key"
                $wifiPass = $this->extractInputValue($wifiHtml, 'WPAPSK_Key') ?: $this->extractInputValue($wifiHtml, 'wifiPass');
                if (!empty($wifiPass)) {
                    $this->result['data']['wifi_password'] = $wifiPass;
                }

                // Canal 2.4GHz (es un DIVid, no un INPUT)
                $canal = $this->extractElementContent($wifiHtml, 'cur_wifi_channel');
                if (!empty($canal)) {
                    $this->result['data']['current_channel_from_html'] = $canal;
                }
            } else {
                $this->result['data']['debug_message'] = 'No autenticado. No se encontró ssidname ni SSID en el HTML de mhs.cgi.';
                $this->result['data']['auth_html_preview'] = substr($wifiHtml, 0, 500);
            }

            // PASO 4: Página WiFi 5GHz (/cgi-bin/wifi5g.cgi)
            $wifi5gResponse = $this->client->get('/cgi-bin/wifi5g.cgi');
            $wifi5gHtml = (string) $wifi5gResponse->getBody();

            if (!empty($wifi5gHtml) && (strpos($wifi5gHtml, 'ssidname') !== false || strpos($wifi5gHtml, 'SSID') !== false)) {
                // SSID 5GHz: Preferimos SSID_5G o similar si existe, sino ssidname
                $ssid5g = $this->extractInputValue($wifi5gHtml, 'SSID_5G') ?: $this->extractInputValue($wifi5gHtml, 'ssidname');
                if (!empty($ssid5g)) {
                    $this->result['data']['ssid_5g'] = $ssid5g;
                }

                // Password WiFi 5GHz
                $wifiPass5g = $this->extractInputValue($wifi5gHtml, 'WPAPSK_Key') ?: $this->extractInputValue($wifi5gHtml, 'wifiPass');
                if (!empty($wifiPass5g)) {
                    $this->result['data']['wifi_password_5g'] = $wifiPass5g;
                }

                // Canal 5GHz (DIVid)
                $canal5g = $this->extractElementContent($wifi5gHtml, 'cur_wifi_channel');
                if (!empty($canal5g)) {
                    $this->result['data']['current_channel_5g'] = $canal5g;
                }
            }

            // PASO 5: Información del sistema (/cgi-bin/sysinfo.cgi)
            $sysinfoResponse = $this->client->get('/cgi-bin/sysinfo.cgi');
            $sysinfoHtml = (string) $sysinfoResponse->getBody();

            if (!empty($sysinfoHtml)) {
                $firmware = $this->extractInputValue($sysinfoHtml, 'fwVersion');
                if (!empty($firmware)) {
                    $this->result['data']['firmware'] = $firmware;
                }

                $serial = $this->extractInputValue($sysinfoHtml, 'serialNumber');
                if (!empty($serial)) {
                    $this->result['data']['numero_serie'] = $serial;
                    $this->result['data']['mac_address']  = $serial;
                }

                $modeloEquipo = $this->extractInputValue($sysinfoHtml, 'model');
                if (!empty($modeloEquipo)) {
                    $this->result['data']['modelo_equipo'] = $modeloEquipo;
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
     * Extrae el atributo value de un <input> dado su id o name.
     */
    private function extractInputValue(string $html, string $fieldId): string
    {
        $escaped = preg_quote($fieldId, '/');

        // Caso 1: id/name antes que el value
        if (preg_match('/<input[^>]*(?:id|name)=["\']' . $escaped . '["\'][^>]*value=["\']([^"\']*)["\']/', $html, $m)) {
            return $m[1];
        }
        // Caso 2: value antes que el id/name
        if (preg_match('/<input[^>]*value=["\']([^"\']*)["\'][^>]*(?:id|name)=["\']' . $escaped . '["\']/', $html, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * Extrae el contenido de un elemento (div, span, etc) dado su id.
     * Ejemplo: <div id="cur_wifi_channel">1</div> -> retorna "1"
     */
    private function extractElementContent(string $html, string $elementId): string
    {
        $escaped = preg_quote($elementId, '/');
        // Regex: busca el id y captura lo que hay entre > y </
        if (preg_match('/id=["\']' . $escaped . '["\'][^>]*>([^<]*)<\//s', $html, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /**
     * Configura el identificador GPON (ONT ID) en el router
     * TODO: Implementar según el HTML del router
     */
    public function configGpon($gpon)
    {
        try {
            // TODO: Implementar según el HTML del router

            return [
                'status' => 'success',
                'message' => 'Método configGpon pendiente de implementación',
                'debug' => [
                    'input_gpon' => $gpon
                ]
            ];

        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => 'Error al configurar GPON: ' . $e->getMessage(),
                'debug_info' => [
                    'input_gpon' => $gpon ?? 'null',
                    'trace' => $e->getTraceAsString()
                ]
            ];
        }
    }

    /**
     * Resetea el identificador GPON (ONT ID) del router a 000000
     * TODO: Implementar según el HTML del router
     */
    public function resetConfigGpon()
    {
        $gpon = '000000';

        try {
            // TODO: Implementar según el HTML del router

            return [
                'status' => 'success',
                'message' => 'Método resetConfigGpon pendiente de implementación',
                'debug' => [
                    'gpon_value' => $gpon
                ]
            ];

        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => 'Error al resetear GPON: ' . $e->getMessage(),
                'debug_info' => [
                    'gpon_value' => $gpon,
                    'trace' => $e->getTraceAsString()
                ]
            ];
        }
    }
}
