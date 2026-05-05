<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Exception;

class RouterScrapper2742
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
     * Ejecuta el scraping del router modelo 2742 (MitraStar GPT-2742GX4X5v6) - WiFi 6
     */
    public function scrape()
    {
        try {
            // PASO 1: Obtener página de login para extraer SID, potencia óptica, MAC, modelo y firmware
            $loginPageResponse = $this->client->get('/cgi-bin/logIn_mhs.cgi');
            $loginPageHtml = (string) $loginPageResponse->getBody();

            $this->result['data']['html_preview'] = substr($loginPageHtml, 0, 500);

            // Extraer potencia óptica desde span#rxPowerValue
            if (preg_match('/id=["\']rxPowerValue["\'][^>]*>\s*([-\d.]+)\s*/s', $loginPageHtml, $matches)) {
                $this->result['data']['potencia_optica'] = $matches[1] . ' dBm';
            } elseif (preg_match('/([-\d.]+)\s*dBm/', $loginPageHtml, $matches)) {
                $this->result['data']['potencia_optica'] = $matches[1] . ' dBm';
            }

            // Extraer MAC desde la página de login: <li><div class="tabID">MAC:</div>XXXXXXXXXXXX</li>
            if (preg_match('/tabID["\']?>MAC:<\/div>\s*([A-Fa-f0-9]{12})/s', $loginPageHtml, $matches)) {
                $rawMac = strtoupper($matches[1]);
                // Formatear como XX:XX:XX:XX:XX:XX
                $this->result['data']['mac_address'] = implode(':', str_split($rawMac, 2));
            }

            // Extraer Modelo desde la página de login
            if (preg_match('/tabID["\']?>Modelo:<\/div>\s*([^<]+)/s', $loginPageHtml, $matches)) {
                $this->result['data']['modelo_equipo'] = trim($matches[1]);
            }

            // Extraer Firmware desde la página de login
            if (preg_match('/tabID["\']?>Firmware:<\/div>\s*([^<]+)/s', $loginPageHtml, $matches)) {
                $this->result['data']['firmware'] = trim($matches[1]);
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

            // PASO 2: Login — syspasswd = hex_md5(password + ":" + sid)
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

            // Guardar HTML 2.4G para debug
            $debugPath24 = storage_path('logs/router_2742_wifi_24g_debug.html');
            file_put_contents($debugPath24, $wifiHtml);
            $this->result['data']['debug_html_24g_saved'] = $debugPath24;

            // Verificamos si logramos entrar (buscando campos conocidos)
            $isAuthenticated = (strpos($wifiHtml, 'ssidname') !== false || strpos($wifiHtml, 'SSID') !== false);
            $this->result['data']['is_authenticated'] = $isAuthenticated ? 'yes' : 'no';

            if ($isAuthenticated) {
                // SSID 2.4GHz: Preferimos el campo oculto "SSID" que tiene el value en el HTML
                $ssid = $this->extractInputValue($wifiHtml, 'SSID') ?: $this->extractInputValue($wifiHtml, 'ssidname');
                if (!empty($ssid)) {
                    $this->result['data']['ssid'] = $ssid;
                }

                // Password WiFi 2.4GHz: Preferimos el campo oculto "WPAPSK_Key"
                $wifiPass = $this->extractInputValue($wifiHtml, 'WPAPSK_Key') ?: $this->extractInputValue($wifiHtml, 'wifiPass');
                if (!empty($wifiPass)) {
                    $this->result['data']['wifi_password'] = $wifiPass;
                }

                // Canal 2.4GHz: viene en variable JS CurrentChannel
                $canal = $this->extractJSVariable($wifiHtml, 'CurrentChannel');
                if ($canal !== '') {
                    $this->result['data']['current_channel'] = $canal;
                }
            } else {
                $this->result['data']['debug_message'] = 'No autenticado. No se encontró ssidname ni SSID en el HTML de mhs.cgi.';
                $this->result['data']['auth_html_preview'] = substr($wifiHtml, 0, 500);
            }

            // PASO 4: Página WiFi 5GHz (/cgi-bin/wifi5g.cgi)
            $wifi5gResponse = $this->client->get('/cgi-bin/wifi5g.cgi');
            $wifi5gHtml = (string) $wifi5gResponse->getBody();

            // Guardar HTML 5G para debug
            $debugPath5g = storage_path('logs/router_2742_wifi_5g_debug.html');
            file_put_contents($debugPath5g, $wifi5gHtml);
            $this->result['data']['debug_html_5g_saved'] = $debugPath5g;

            if ($isAuthenticated || strpos($wifi5gHtml, 'SSID') !== false) {
                // SSID 5GHz (en WiFi 6 es el mismo que 2.4GHz, pero extraemos igualmente)
                $ssid5g = $this->extractInputValue($wifi5gHtml, 'SSID') ?: $this->extractInputValue($wifi5gHtml, 'ssidname');
                if (!empty($ssid5g)) {
                    $this->result['data']['ssid_5g'] = $ssid5g;
                }

                // Password WiFi 5GHz (en WiFi 6 es la misma que 2.4GHz)
                $wifiPass5g = $this->extractInputValue($wifi5gHtml, 'WPAPSK_Key') ?: $this->extractInputValue($wifi5gHtml, 'wifiPass');
                if (!empty($wifiPass5g)) {
                    $this->result['data']['wifi_password_5g'] = $wifiPass5g;
                }

                // Canal 5GHz: viene en variable JS CurrentChannel
                $canal5g = $this->extractJSVariable($wifi5gHtml, 'CurrentChannel');
                if ($canal5g !== '') {
                    $this->result['data']['current_channel_5g'] = $canal5g;
                }
            }

            // PASO 5: Cerrar sesión antes de ir a instalación
            $this->client->get('/cgi-bin/Logout.cgi');

            // PASO 6: Página de Instalación — login con usuario "Support"
            if ($this->loginInstallation()) {
                // Ir a la página de ONT/SLID (/cgi-bin/Instalacion_ontpw.cgi)
                $ontResponse = $this->client->get('/cgi-bin/Instalacion_ontpw.cgi');
                $ontHtml = (string) $ontResponse->getBody();

                // Guardar para debug
                file_put_contents(storage_path('logs/router_2742_inst_debug.html'), $ontHtml);

                // Extraer MAC Address: <span class="sub">Direcciones MAC: <br>XX:XX:XX:XX:XX:XX</span>
                if (preg_match('/Direcciones MAC:.*?<br>\s*([A-Fa-f0-9:]+)/is', $ontHtml, $m)) {
                    $mac = trim($m[1]);
                    $this->result['data']['mac_address'] = $mac;
                }

                // Número de serie = MAC sin los ":"
                if (!empty($this->result['data']['mac_address'])) {
                    $this->result['data']['numero_serie'] = str_replace(':', '', $this->result['data']['mac_address']);
                }

                // Extraer SLID (GPON Password) desde SLIDHexValue
                $slidHex = $this->extractJSVariable($ontHtml, 'SLIDHexValue');
                if ($slidHex !== '') {
                    $slidText = $this->hex2ascii($slidHex);
                    $this->result['data']['gpon_password'] = $slidText;
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
     * Extrae únicamente la potencia óptica (dBm) desde la página de login
     */
    public function sacarDbm()
    {
        try {
            $loginPageResponse = $this->client->get('/cgi-bin/logIn_mhs.cgi');
            $loginPageHtml = (string) $loginPageResponse->getBody();

            // Extraer potencia óptica desde span#rxPowerValue
            if (preg_match('/id=["\']rxPowerValue["\'][^>]*>\s*([-\d.]+)\s*/s', $loginPageHtml, $matches)) {
                $this->result['data']['potencia_optica'] = $matches[1] . ' dBm';
            } elseif (preg_match('/([-\d.]+)\s*dBm/', $loginPageHtml, $matches)) {
                $this->result['data']['potencia_optica'] = $matches[1] . ' dBm';
            } else {
                $this->result['data']['error'] = 'No se encontró la potencia óptica en la página de login';
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

            // Convertimos el GPON ASCII a HEX, padding de 20 y mayúsculas
            $slidHex = $this->ascii2hex($gpon);

            // POST a la página de configuración
            $response = $this->client->post('/cgi-bin/Instalacion_ontpw.cgi', [
                'form_params' => [
                    'SLID'        => $slidHex,
                    'submitValue' => '1',
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                return [
                    'status'  => 'success',
                    'message' => 'GPON configurado correctamente. El router podría reiniciarse.',
                    'data'    => [
                        'gpon_ascii' => $gpon,
                        'gpon_hex'   => $slidHex
                    ]
                ];
            }

            throw new Exception("Error al enviar la configuración GPON.");

        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error'  => $e->getMessage()
            ];
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
     * Inicia sesión en la página de instalación (/cgi-bin/logIn_Instalacion.cgi)
     * Requiere usuario "Support" y la contraseña del router
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
                    'syspasswd_1' => '',
                    'submitValue' => '1',
                    'leaveBlur'   => '0'
                ]
            ]);
            return true;
        }
        return false;
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
     * Extrae el valor de una variable JavaScript.
     * Ejemplo: var CurrentChannel='149'; -> retorna "149"
     */
    private function extractJSVariable(string $html, string $variableName): string
    {
        $escaped = preg_quote($variableName, '/');
        $pattern = '/(?:var\s+)?' . $escaped . '\s*=\s*[\'"]([^\'"]+)[\'"]\s*;?/i';

        if (preg_match($pattern, $html, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * Convierte una cadena hexadecimal a ASCII, eliminando caracteres nulos.
     */
    private function hex2ascii(string $hex): string
    {
        $str = '';
        for ($i = 0; $i < strlen($hex); $i += 2) {
            $char = chr(hexdec(substr($hex, $i, 2)));
            if ($char !== "\x00") {
                $str .= $char;
            }
        }
        return trim($str);
    }

    /**
     * Convierte una cadena ASCII a HEX, rellenando hasta 20 caracteres con '0' a la derecha.
     */
    private function ascii2hex(string $ascii): string
    {
        $hex = '';
        for ($i = 0; $i < strlen($ascii); $i++) {
            $h = dechex(ord($ascii[$i]));
            $hex .= (strlen($h) < 2 ? '0' . $h : $h);
        }
        $hex = strtoupper($hex);
        return str_pad($hex, 20, '0', STR_PAD_RIGHT);
    }

    /**
     * Extrae el contenido de un elemento dado su id.
     */
    private function extractElementContent(string $html, string $elementId): string
    {
        $escaped = preg_quote($elementId, '/');

        if (preg_match_all('/id=["\']' . $escaped . '["\'][^>]*>([^<]+)<\//s', $html, $matches)) {
            foreach ($matches[1] as $content) {
                $trimmed = trim($content);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        if (preg_match('/id=["\']' . $escaped . '["\'][^>]*>([^<]*)<\//s', $html, $m)) {
            return trim($m[1]);
        }

        return '';
    }
}
