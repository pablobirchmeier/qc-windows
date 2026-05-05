<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Exception;

class RouterScrapper8225
{
    private $client;
    private $cookieJar;
    private $ip;
    private $password;
    private $result;

    public function __construct($ip, $username, $password, $modelo = 'Router')
    {
        $this->ip = $ip;
        // Nota: $username se recibe pero no se usa porque el router tiene usuarios fijos:
        // - 'user' para WiFi
        // - 'Support' para instalación
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
     * Replica la función mess_userpass() del JavaScript del router.
     * XOR-ea cada carácter con 0x1f.
     */
    private function mess_userpass($str)
    {
        $result = '';
        for ($i = 0; $i < strlen($str); $i++) {
            $ascii = ord($str[$i]);
            $result .= chr($ascii ^ 0x1f);
        }
        return $result;
    }

    /**
     * Ejecuta el scraping del router modelo 8225 (Askey RTF8225VW).
     * Flujo:
     *   1) GET /te_wifi.asp           → frame inicial, captura potencia óptica del JS
     *   2) POST /cgi-bin/te_acceso_router.cgi (user/$password con XOR mess_userpass)
     *   3) GET /te_wifi.asp           → SSID/password/canal 2.4GHz (variables JS)
     *   4) GET /te_wifi_5ghz.asp      → SSID/password/canal 5GHz (variables JS)
     *   5) GET /index_instalacion.asp + POST login Support
     *   6) GET /me_install.asp        → array info[] (fabricante, modelo, firmware,
     *                                    status, nº serie, MAC, potencia óptica) + gponPw
     */
    public function scrape()
    {
        try {
            // PASO 1: Obtener frame WiFi 2.4GHz (sin login todavía)
            $frameResponse = $this->client->get('/te_wifi.asp');
            $frameHtml = (string) $frameResponse->getBody();

            if (empty($frameHtml)) {
                throw new Exception("No se pudo obtener te_wifi.asp");
            }

            // Potencia óptica desde el JS (a veces aparece en este frame)
            if (preg_match('/RX:(-?[\d\.]+)\s*dBm/', $frameHtml, $matches)) {
                $this->result['data']['rx_optica'] = $matches[1] . ' dBm';
            }
            $this->result['data']['password'] = $this->password;

            // PASO 2: Login con usuario 'user' (XOR mess_userpass aplicado)
            $transformedUsername = $this->mess_userpass('user');
            $transformedPassword = $this->mess_userpass($this->password);

            $loginResponse = $this->client->post('/cgi-bin/te_acceso_router.cgi', [
                'form_params' => [
                    'curWebPage' => '/te_wifi.asp',
                    'loginUsername' => $transformedUsername,
                    'loginPassword' => $transformedPassword
                ]
            ]);
            $this->result['data']['login_status_code'] = $loginResponse->getStatusCode();
            $this->result['data']['login_response_preview'] = substr((string) $loginResponse->getBody(), 0, 300);

            $cookies = $this->cookieJar->toArray();
            $this->result['data']['cookies_after_login'] = count($cookies) > 0 ? 'yes (' . count($cookies) . ')' : 'no';

            // PASO 3: Página WiFi 2.4GHz autenticada
            $authResponse = $this->client->get('/te_wifi.asp');
            $authHtml = (string) $authResponse->getBody();
            $this->result['data']['auth_status_code'] = $authResponse->getStatusCode();

            $isAuthenticated = (strpos($authHtml, 'var wifiSSIDName') !== false);
            $this->result['data']['is_authenticated'] = $isAuthenticated ? 'yes' : 'no';

            if ($isAuthenticated && !empty($authHtml)) {
                // SSID 2.4GHz
                if (preg_match('/var wifiSSIDName = htmlDecode\([\'"]([^\'"]*?)[\'"]\)/', $authHtml, $matches)) {
                    $this->result['data']['ssid'] = htmlspecialchars_decode($matches[1]);
                }

                // Canal actual 2.4GHz (variable JS)
                if (preg_match('/var wifiCurrentChannel = [\'"](\d+)[\'"]/', $authHtml, $matches)) {
                    $this->result['data']['current_channel'] = $matches[1];
                }

                // Password WiFi 2.4GHz (WPA-PSK)
                if (preg_match('/var wpaPskKey = htmlDecode\([\'"]([^\'"]*?)[\'"]\)/', $authHtml, $matches)) {
                    $this->result['data']['wifi_password'] = htmlspecialchars_decode($matches[1]);
                }

                // Tipo de autenticación
                if (preg_match('/var wifiAuthMode = [\'"]([^\'"]+)[\'"]/', $authHtml, $matches)) {
                    $this->result['data']['auth_type'] = $matches[1];
                    $authModes = [
                        '00' => 'Sin cifrado',
                        '02' => 'WEP',
                        '34' => 'WPA2(AES)',
                        '44' => 'WPA/WPA2 Mixto'
                    ];
                    $this->result['data']['auth_type_name'] = $authModes[$matches[1]] ?? 'Desconocido';
                }

                // Estado WiFi
                if (preg_match('/var wifiActive = [\'"]([^\'"]+)[\'"]/', $authHtml, $matches)) {
                    $this->result['data']['wifi_active'] = $matches[1] == '1' ? 'Activado' : 'Desactivado';
                }

                // Canal actual desde el HTML (<td name="currentChannel">N</td>)
                if (preg_match('/<td[^>]*name="currentChannel"[^>]*>(\d+)<\/td>/', $authHtml, $matches)) {
                    $this->result['data']['current_channel_from_html'] = $matches[1];
                }
            } else {
                $this->result['data']['debug_message'] = 'No autenticado o HTML inválido. No se encontró var wifiSSIDName en el HTML.';
                $this->result['data']['auth_html_debug'] = $authHtml;
            }

            // PASO 4: Página WiFi 5GHz
            $frameResponse5g = $this->client->get('/te_wifi_5ghz.asp');
            $frameHtml5g = (string) $frameResponse5g->getBody();

            if (empty($frameHtml5g)) {
                throw new Exception("No se pudo obtener te_wifi_5ghz.asp");
            }

            if (preg_match('/var wifiSSIDName = htmlDecode\([\'"]([^\'"]*?)[\'"]\)/', $frameHtml5g, $matches)) {
                $this->result['data']['ssid_5g'] = htmlspecialchars_decode($matches[1]);
            }

            if (preg_match('/var wifiCurrentChannel = [\'"](\d+)[\'"]/', $frameHtml5g, $matches)) {
                $this->result['data']['current_channel_5g'] = $matches[1];
            }

            if (preg_match('/var wpaPskKey = htmlDecode\([\'"]([^\'"]*?)[\'"]\)/', $frameHtml5g, $matches)) {
                $this->result['data']['wifi_password_5g'] = htmlspecialchars_decode($matches[1]);
            }

            if (preg_match('/var wifiActive = [\'"]([^\'"]+)[\'"]/', $frameHtml5g, $matches)) {
                $this->result['data']['wifi_active_5g'] = $matches[1] == '1' ? 'Activado' : 'Desactivado';
            }

            // PASO 5: Página de instalación (login con usuario 'Support')
            $this->client->get('/index_instalacion.asp');

            $transformedUsernameSupport = $this->mess_userpass('Support');
            $transformedPasswordSupport = $this->mess_userpass($this->password);

            $this->client->post('/cgi-bin/te_acceso_router.cgi', [
                'form_params' => [
                    'curWebPage' => '/me_install.asp',
                    'loginUsername' => $transformedUsernameSupport,
                    'loginPassword' => $transformedPasswordSupport
                ]
            ]);

            // PASO 6: me_install.asp — array info[] tiene los datos reales.
            // OJO: la tabla HTML llega VACÍA, los valores los rellena el JS desde info[].
            $authInstalacionResponse = $this->client->get('/me_install.asp');
            $authInstalacionHtml = (string) $authInstalacionResponse->getBody();

            if (!empty($authInstalacionHtml)) {
                // info[0] = 'Askey'
                if (preg_match('/info\[0\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $authInstalacionHtml, $matches)) {
                    $this->result['data']['fabricante'] = trim($matches[1]);
                }

                // info[1] = 'RTF8225VW'
                if (preg_match('/info\[1\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $authInstalacionHtml, $matches)) {
                    $this->result['data']['modelo_equipo'] = trim($matches[1]);
                }

                // info[2] = 'CL_g1.17_RTF_TEF004_V2.55'
                if (preg_match('/info\[2\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $authInstalacionHtml, $matches)) {
                    $this->result['data']['firmware'] = trim($matches[1]);
                }

                // info[3] = '1' (Internet en operación) o cualquier otro valor (sin configurar)
                if (preg_match('/info\[3\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $authInstalacionHtml, $matches)) {
                    $this->result['data']['router_status'] = trim($matches[1]) == '1' ? 'Internet en operación' : 'Internet está sin configurar';
                }

                // info[4] = '90D3CFC360A1' (Nº serie)
                if (preg_match('/info\[4\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $authInstalacionHtml, $matches)) {
                    $this->result['data']['numero_serie'] = trim($matches[1]);
                }

                // info[5] = '90:D3:CF:C3:60:A0' (MAC)
                if (preg_match('/info\[5\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $authInstalacionHtml, $matches)) {
                    $this->result['data']['mac_address'] = trim($matches[1]);
                }

                // info[6] = 'TX:...;RX:-40.000000 dBm;LT:...;LR:...' → potencia óptica RX
                if (preg_match('/info\[6\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $authInstalacionHtml, $matches)) {
                    $potencia = trim($matches[1]);
                    if (preg_match('/RX:(-?[\d\.]+)/', $potencia, $rxMatch)) {
                        $this->result['data']['potencia_optica'] = $rxMatch[1] . ' dBm';
                    } else {
                        $this->result['data']['potencia_optica'] = $potencia;
                    }
                }

                // GPON Password actual (texto plano, no hex en el 8225)
                if (preg_match('/var gponPw\s*=\s*[\'"]([^\'"]*)[\'"]/', $authInstalacionHtml, $matches)) {
                    $this->result['data']['gpon_password'] = trim($matches[1]);
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
     * Extrae únicamente la potencia óptica (dBm) directamente desde te_wifi.asp
     * sin hacer login completo.
     */
    public function sacarDbm()
    {
        try {
            $frameResponse = $this->client->get('/te_wifi.asp');
            $frameHtml = (string) $frameResponse->getBody();

            if (preg_match('/RX:(-?[\d\.]+)\s*dBm/', $frameHtml, $matches)) {
                $this->result['data']['rx_optica'] = $matches[1] . ' dBm';
                $this->result['data']['potencia_optica'] = $matches[1] . ' dBm';
            } else {
                $this->result['data']['error'] = 'No se encontró la potencia óptica en te_wifi.asp';
            }

            $this->result['status'] = 'success';

        } catch (Exception $e) {
            $this->result['status'] = 'error';
            $this->result['error'] = $e->getMessage();
        }

        return $this->result;
    }

    /**
     * Configura el identificador GPON (ONT ID) en el router.
     * Login Support → POST /cgi-bin/te_install.cmd con sessionKey + gponAsciiPw (texto plano).
     */
    public function configGpon($gpon)
    {
        $sessionKey = null;

        try {
            // PASO 1: Inicializar sesión en página de instalación
            $this->client->get('/index_instalacion.asp');

            // PASO 2: Login con usuario 'Support' (XOR mess_userpass)
            $transformedUsernameSupport = $this->mess_userpass('Support');
            $transformedPasswordSupport = $this->mess_userpass($this->password);

            $loginResponse = $this->client->post('/cgi-bin/te_acceso_router.cgi', [
                'form_params' => [
                    'curWebPage' => '/me_install.asp',
                    'loginUsername' => $transformedUsernameSupport,
                    'loginPassword' => $transformedPasswordSupport
                ]
            ]);

            if ($loginResponse->getStatusCode() !== 200) {
                throw new Exception("Error al autenticar en el portal de instalación.");
            }

            // PASO 3: Obtener me_install.asp para extraer sessionKey
            $installResponse = $this->client->get('/me_install.asp');
            $installHtml = (string) $installResponse->getBody();

            if (!preg_match("/var sessionKey = '(\d+)';/", $installHtml, $matches)) {
                throw new Exception("No se pudo obtener la sessionKey desde me_install.asp");
            }
            $sessionKey = $matches[1];

            // PASO 4: POST a /cgi-bin/te_install.cmd con gponAsciiPw (texto plano, no hex)
            $body = http_build_query([
                'sessionKey' => $sessionKey,
                'gponAsciiPw' => (string) $gpon,
                'acsInstall' => '1',
                'gponUp' => '0',
                'onuState' => '0'
            ]);

            $configResponse = $this->client->post('/cgi-bin/te_install.cmd', [
                'body' => $body,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Referer' => "http://{$this->ip}/me_install.asp",
                    'Content-Length' => strlen($body),
                ],
                'expect' => false,
                'curl' => [
                    CURLOPT_FORBID_REUSE => true,
                    CURLOPT_FRESH_CONNECT => true,
                    CURLOPT_HTTP09_ALLOWED => true,
                ]
            ]);

            return [
                'status' => 'success',
                'message' => 'Configuración GPON enviada correctamente',
                'debug' => [
                    'input_gpon' => $gpon,
                    'session_key' => $sessionKey,
                    'http_status' => $configResponse->getStatusCode()
                ]
            ];

        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => 'Error al configurar GPON: ' . $e->getMessage(),
                'debug_info' => [
                    'input_gpon' => $gpon ?? 'null',
                    'session_key' => $sessionKey ?? 'not_found',
                    'trace' => $e->getTraceAsString()
                ]
            ];
        }
    }

    /**
     * Resetea el identificador GPON (ONT ID) del router a 000000.
     */
    public function resetConfigGpon()
    {
        return $this->configGpon('000000');
    }
}
