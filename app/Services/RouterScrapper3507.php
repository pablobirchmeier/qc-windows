<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Exception;

class RouterScrapper3507
{
    private $client;
    private $cookieJar;
    private $ip;
    private $password;
    private $result;

    public function __construct($ip, $username, $password, $modelo = 'Router')
    {
        $this->ip = $ip;
        // Nota: $username se recibe pero no se usa porque el router tiene usuarios fijos
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
                'Referer' => "http://{$ip}",
            ],
        ]);
    }

    /**
     * Ejecuta el scraping del router modelo 3507 (Askey RTF3507VW)
     * Flujo:
     *   1) GET /                  → frameset, inicializa sesión y captura cookie
     *   2) POST /te_acceso_router.cgi con loginUsername=user / loginPassword=$password
     *   3) GET /te_wifi.html      → SSID/password/canal 2.4GHz (variables JS)
     *   4) GET /te_wifi_5ghz.html → SSID/password/canal 5GHz (variables JS)
     *   5) POST /te_acceso_router.cgi con loginUsername=Installation / loginPassword=Te2ef7n2c4hgu2
     *   6) GET /install.html      → tabla "routerinfo" (fabricante, modelo, firmware,
     *                                 status, nº serie, MAC, potencia óptica) + gponPw (JS)
     */
    public function scrape()
    {
        try {
            // PASO 1: Obtener sessionID desde la raíz
            $rootResponse = $this->client->get('/');
            $rootStatusCode = $rootResponse->getStatusCode();
            $this->result['data']['root_status_code'] = $rootStatusCode;

            $cookies = $this->cookieJar->toArray();
            $this->result['data']['cookies_after_root'] = count($cookies) > 0 ? 'yes (' . count($cookies) . ')' : 'no';

            if (count($cookies) > 0) {
                $this->result['data']['sessionID'] = $cookies[0]['Value'];
            }

            // PASO 2: Login con usuario 'user' y la password recibida
            // El form HTML tiene inputs name="Username" y name="Password" pero el JS
            // los copia a los hidden loginUsername/loginPassword antes de enviar.
            $loginResponse = $this->client->post('/te_acceso_router.cgi', [
                'form_params' => [
                    'loginUsername' => 'user',
                    'loginPassword' => $this->password
                ],
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Referer' => "http://{$this->ip}/",
                ]
            ]);
            $this->result['data']['login_status_code'] = $loginResponse->getStatusCode();

            // PASO 3: Página WiFi 2.4GHz (los datos están en variables JS)
            $authResponse = $this->client->get('/te_wifi.html');
            $authHtml = (string) $authResponse->getBody();
            $authStatusCode = $authResponse->getStatusCode();
            $this->result['data']['wifi_page_status_code'] = $authStatusCode;

            $isAuthenticated = (strpos($authHtml, 'var wifiSSIDName') !== false);
            $this->result['data']['is_authenticated'] = $isAuthenticated ? 'yes' : 'no';

            if ($isAuthenticated && !empty($authHtml)) {
                // SSID 2.4G
                if (preg_match('/var wifiSSIDName = htmlDecode\([\'"]([^\'"]*?)[\'"]\)/', $authHtml, $matches)) {
                    $this->result['data']['ssid'] = htmlspecialchars_decode($matches[1]);
                }

                // Canal actual 2.4G (variable JS)
                if (preg_match('/var wifiCurrentChannel = [\'"](\d+)[\'"]/', $authHtml, $matches)) {
                    $this->result['data']['current_channel'] = $matches[1];
                }

                // Password WiFi 2.4G (WPA-PSK)
                if (preg_match('/var wpaPskKey = htmlDecode\([\'"]([^\'"]*?)[\'"]\)/', $authHtml, $matches)) {
                    $this->result['data']['wifi_password'] = htmlspecialchars_decode($matches[1]);
                }

                // Estado WiFi
                if (preg_match('/var wifiActive = [\'"]([^\'"]+)[\'"]/', $authHtml, $matches)) {
                    $this->result['data']['wifi_active'] = $matches[1] == '1' ? 'Activado' : 'Desactivado';
                }

                // Canal actual desde el HTML (<td name="currentChannel">11</td>)
                if (preg_match('/<td[^>]*name="currentChannel"[^>]*>(\d+)<\/td>/', $authHtml, $matches)) {
                    $this->result['data']['current_channel_from_html'] = $matches[1];
                }
            } else {
                $this->result['data']['debug_message'] = 'No autenticado o HTML inválido. No se encontró var wifiSSIDName en el HTML.';
                $this->result['data']['auth_html_debug'] = $authHtml;
            }

            // PASO 4: Página WiFi 5GHz
            $frameResponse5g = $this->client->get('/te_wifi_5ghz.html');
            $frameHtml5g = (string) $frameResponse5g->getBody();

            if (empty($frameHtml5g)) {
                throw new Exception("No se pudo obtener te_wifi_5ghz.html");
            }

            // SSID 5G
            if (preg_match('/var wifiSSIDName = htmlDecode\([\'"]([^\'"]*?)[\'"]\)/', $frameHtml5g, $matches)) {
                $this->result['data']['ssid_5g'] = htmlspecialchars_decode($matches[1]);
            }

            // Canal actual 5G
            if (preg_match('/var wifiCurrentChannel = [\'"](\d+)[\'"]/', $frameHtml5g, $matches)) {
                $this->result['data']['current_channel_5g'] = $matches[1];
            }

            // Password WiFi 5G
            if (preg_match('/var wpaPskKey = htmlDecode\([\'"]([^\'"]*?)[\'"]\)/', $frameHtml5g, $matches)) {
                $this->result['data']['wifi_password_5g'] = htmlspecialchars_decode($matches[1]);
            }

            // Estado WiFi 5G
            if (preg_match('/var wifiActive = [\'"]([^\'"]+)[\'"]/', $frameHtml5g, $matches)) {
                $this->result['data']['wifi_active_5g'] = $matches[1] == '1' ? 'Activado' : 'Desactivado';
            }

            // PASO 5: Login en Instalación
            try {
                $loginInstallResponse = $this->client->post('/te_acceso_router.cgi', [
                    'form_params' => [
                        'loginUsername' => 'Installation',
                        'loginPassword' => 'Te2ef7n2c4hgu2'
                    ],
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Referer' => "http://{$this->ip}/",
                    ]
                ]);
                $this->result['data']['install_login_status'] = $loginInstallResponse->getStatusCode();
            } catch (Exception $loginInstallError) {
                $this->result['data']['install_login_error'] = $loginInstallError->getMessage();
            }

            // PASO 6: Página de instalación (install.html) — tabla "routerinfo"
            $installResponse = $this->client->get('/install.html');
            $installHtml = (string) $installResponse->getBody();
            $installStatusCode = $installResponse->getStatusCode();
            $this->result['data']['install_page_status'] = $installStatusCode;

            // Verificar acceso buscando el array JS info[] (donde viven los datos reales).
            // OJO: la tabla HTML "routerinfo" llega VACÍA del servidor — el JS de jQuery
            // del router la rellena en runtime con $('td:eq(N)').find('b').after(info[N]).
            // Como Guzzle no ejecuta JS, leemos directamente las asignaciones del array.
            $hasInstallAccess = (strpos($installHtml, 'info[0]') !== false || strpos($installHtml, 'class="routerinfo"') !== false);
            $this->result['data']['install_access'] = $hasInstallAccess ? 'yes' : 'no';

            if ($hasInstallAccess) {
                // info[0] = 'Askey';                         → Fabricante
                if (preg_match('/info\[0\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $installHtml, $matches)) {
                    $this->result['data']['fabricante'] = trim($matches[1]);
                }

                // info[1] = 'RTF3507VW';                     → Modelo (con prefijo RTF asegurado)
                if (preg_match('/info\[1\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $installHtml, $matches)) {
                    $modelo = trim($matches[1]);
                    $this->result['data']['modelo_equipo'] = (strpos($modelo, 'RTF') !== 0) ? 'RTF' . $modelo : $modelo;
                }

                // info[2] = 'CL_g000_R3507VWCL203_n32';      → Firmware
                if (preg_match('/info\[2\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $installHtml, $matches)) {
                    $this->result['data']['firmware'] = trim($matches[1]);
                }

                // info[3] = 'Connected'|'Unconfigured';      → Estado del router
                if (preg_match('/info\[3\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $installHtml, $matches)) {
                    $status = trim($matches[1]);
                    // El JS muestra: Connected → 'Internet está configurado', else → 'sin configurar'
                    $this->result['data']['router_status'] = ($status === 'Connected')
                        ? 'Internet está configurado'
                        : 'Internet está sin configurar';
                }

                // info[4] = '7829ED08AD3E';                  → Nº de serie
                if (preg_match('/info\[4\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $installHtml, $matches)) {
                    $this->result['data']['numero_serie'] = trim($matches[1]);
                }

                // info[5] = '78:29:ed:08:ad:3b';             → MAC
                if (preg_match('/info\[5\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $installHtml, $matches)) {
                    $this->result['data']['mac_address'] = trim($matches[1]);
                }

                // info[6] = 'TX:-11.146388 dBm;RX:-40.000000 dBm'; → Potencia óptica (extraer RX)
                if (preg_match('/info\[6\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $installHtml, $matches)) {
                    $potencia = trim($matches[1]);
                    if (preg_match('/RX:([-\d.]+)/', $potencia, $rxMatch)) {
                        $this->result['data']['potencia_optica'] = $rxMatch[1] . ' dBm';
                    } else {
                        $this->result['data']['potencia_optica'] = $potencia . (strpos($potencia, 'dBm') === false ? ' dBm' : '');
                    }
                }

                // GPON Password actual (en hex en variable JS, decodificar a texto)
                if (preg_match('/var gponPw\s*=\s*[\'"]([^\'"]+)[\'"]/', $installHtml, $matches)) {
                    $gponHex = trim($matches[1]);
                    $gponText = '';
                    for ($i = 0; $i < strlen($gponHex); $i += 2) {
                        $hexPair = substr($gponHex, $i, 2);
                        if ($hexPair === '00') break; // padding
                        $gponText .= chr(hexdec($hexPair));
                    }
                    $this->result['data']['gpon_password'] = $gponText;
                }
            } else {
                $this->result['data']['install_html_preview'] = substr($installHtml, 0, 500);
            }

            $this->result['status'] = 'success';

        } catch (Exception $e) {
            $this->result['status'] = 'error';
            $this->result['error'] = $e->getMessage();
            $this->result['data']['error_trace'] = $e->getTraceAsString();
        }

        return $this->result;
    }

    /**
     * Extrae únicamente la potencia óptica (dBm) del router.
     * Login Installation → GET /install.html → tabla routerinfo.
     */
    public function sacarDbm()
    {
        try {
            // Inicializar sesión obteniendo la raíz
            $this->client->get('/');

            // Login en Instalación
            try {
                $this->client->post('/te_acceso_router.cgi', [
                    'form_params' => [
                        'loginUsername' => 'Installation',
                        'loginPassword' => 'Te2ef7n2c4hgu2'
                    ],
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Referer' => "http://{$this->ip}/",
                    ]
                ]);
            } catch (Exception $loginInstallError) {
                // A veces no responde pero igual autentica; continuamos
            }

            // Obtener install.html
            $installResponse = $this->client->get('/install.html');
            $installHtml = (string) $installResponse->getBody();

            $hasInstallAccess = (strpos($installHtml, 'info[6]') !== false || strpos($installHtml, 'class="routerinfo"') !== false);

            if ($hasInstallAccess) {
                // info[6] = 'TX:-11.146388 dBm;RX:-40.000000 dBm'  → potencia óptica RX
                // El HTML de la tabla viene vacío (lo rellena el JS); leemos del array directo.
                if (preg_match('/info\[6\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $installHtml, $matches)) {
                    $potencia = trim($matches[1]);
                    if (preg_match('/RX:([-\d.]+)/', $potencia, $rxMatch)) {
                        $this->result['data']['potencia_optica'] = $rxMatch[1] . ' dBm';
                    } else {
                        $this->result['data']['potencia_optica'] = $potencia . (strpos($potencia, 'dBm') === false ? ' dBm' : '');
                    }
                }
            } else {
                $this->result['data']['error'] = 'No se pudo acceder a install.html';
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
     * Login Installation → GET /install.html (sessionKey) → POST /te_install.cmd con sessionKey + gponPw hex.
     * El JS del router hace exactamente esto:
     *   gponPw = $('#gponpw').val().replace(/(.)/g, function(x){return x.charCodeAt().toString(16);});
     *   gponPw = (gponPw+'00000000000000000000').substr(0, 20);
     *   $.post('te_install.cmd', {sessionKey, gponPw});
     */
    public function configGpon($gpon)
    {
        $sessionKey = null;
        $gponPw = null;

        try {
            // PASO 0: Inicializar sesión
            $this->client->get('/');

            // PASO 1: Login en Instalación
            $loginResponse = $this->client->post('/te_acceso_router.cgi', [
                'body' => http_build_query([
                    'loginUsername' => 'Installation',
                    'loginPassword' => 'Te2ef7n2c4hgu2'
                ]),
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Referer' => "http://{$this->ip}/",
                ],
                'expect' => false
            ]);

            if ($loginResponse->getStatusCode() !== 200) {
                throw new Exception("Error al autenticar en el portal de instalación.");
            }

            // PASO 2: Obtener install.html para extraer sessionKey
            $installResponse = $this->client->get('/install.html');
            $installHtml = (string) $installResponse->getBody();

            if (!preg_match("/var sessionKey = '(\d+)';/", $installHtml, $matches)) {
                throw new Exception("No se pudo obtener la sessionKey desde install.html");
            }
            $sessionKey = $matches[1];

            // PASO 3: Convertir GPON a hex (cada char → 2 dígitos hex) y padding a 20 chars
            $gponPw = '';
            $gponInput = (string) $gpon;
            for ($i = 0; $i < strlen($gponInput); $i++) {
                $gponPw .= dechex(ord($gponInput[$i]));
            }
            $gponPw = substr($gponPw . '00000000000000000000', 0, 20);

            // PASO 4: POST a te_install.cmd
            $body = http_build_query([
                'sessionKey' => $sessionKey,
                'gponPw' => $gponPw
            ]);

            $configResponse = $this->client->post('/te_install.cmd', [
                'body' => $body,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Referer' => "http://{$this->ip}/install.html",
                    'Content-Length' => strlen($body),
                ],
                'expect' => false,
                'curl' => [
                    CURLOPT_FORBID_REUSE => true,
                    CURLOPT_FRESH_CONNECT => true,
                ]
            ]);

            return [
                'status' => 'success',
                'message' => 'Configuración GPON enviada correctamente',
                'debug' => [
                    'input_gpon' => $gpon,
                    'converted_gpon_hex' => $gponPw,
                    'session_key' => $sessionKey,
                    'request_body' => $body,
                    'http_status' => $configResponse->getStatusCode()
                ]
            ];

        } catch (Exception $e) {
            // Errores de red conocidos en routers Askey que suelen significar éxito:
            // el router cierra la conexión abruptamente tras procesar el POST.
            $msg = $e->getMessage();
            if (strpos($msg, 'HTTP/0.9') !== false || strpos($msg, 'rewind') !== false || strpos($msg, 'Empty reply') !== false) {
                return [
                    'status' => 'success',
                    'message' => 'Configuración GPON enviada (advertencia de red ignorada)',
                    'debug' => [
                        'input_gpon' => $gpon,
                        'converted_gpon_hex' => $gponPw ?? 'unknown',
                        'note' => 'Network error ignored as potential success: ' . $msg
                    ]
                ];
            }

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
     * Mismo flujo que configGpon pero con GPON fijo '000000'.
     */
    public function resetConfigGpon()
    {
        $gpon = '000000';
        $sessionKey = null;
        $gponPw = null;

        try {
            $this->client->get('/');

            $loginResponse = $this->client->post('/te_acceso_router.cgi', [
                'body' => http_build_query([
                    'loginUsername' => 'Installation',
                    'loginPassword' => 'Te2ef7n2c4hgu2'
                ]),
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Referer' => "http://{$this->ip}/",
                ],
                'expect' => false
            ]);

            if ($loginResponse->getStatusCode() !== 200) {
                throw new Exception("Error al autenticar en el portal de instalación.");
            }

            $installResponse = $this->client->get('/install.html');
            $installHtml = (string) $installResponse->getBody();

            if (!preg_match("/var sessionKey = '(\d+)';/", $installHtml, $matches)) {
                throw new Exception("No se pudo obtener la sessionKey desde install.html");
            }
            $sessionKey = $matches[1];

            // Convertir '000000' a hex y padding a 20 chars
            $gponPw = '';
            for ($i = 0; $i < strlen($gpon); $i++) {
                $gponPw .= dechex(ord($gpon[$i]));
            }
            $gponPw = substr($gponPw . '00000000000000000000', 0, 20);

            $body = http_build_query([
                'sessionKey' => $sessionKey,
                'gponPw' => $gponPw
            ]);

            $configResponse = $this->client->post('/te_install.cmd', [
                'body' => $body,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Referer' => "http://{$this->ip}/install.html",
                    'Content-Length' => strlen($body),
                ],
                'expect' => false,
                'curl' => [
                    CURLOPT_FORBID_REUSE => true,
                    CURLOPT_FRESH_CONNECT => true,
                ]
            ]);

            return [
                'status' => 'success',
                'message' => 'GPON reseteado a 000000 correctamente',
                'debug' => [
                    'gpon_value' => $gpon,
                    'converted_gpon_hex' => $gponPw,
                    'session_key' => $sessionKey,
                    'http_status' => $configResponse->getStatusCode()
                ]
            ];

        } catch (Exception $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'HTTP/0.9') !== false || strpos($msg, 'rewind') !== false || strpos($msg, 'Empty reply') !== false) {
                return [
                    'status' => 'success',
                    'message' => 'GPON reseteado a 000000 (advertencia de red ignorada)',
                    'debug' => [
                        'gpon_value' => $gpon,
                        'converted_gpon_hex' => $gponPw ?? 'unknown',
                        'note' => 'Network error ignored as potential success: ' . $msg
                    ]
                ];
            }

            return [
                'status' => 'error',
                'error' => 'Error al resetear GPON: ' . $e->getMessage(),
                'debug_info' => [
                    'gpon_value' => $gpon,
                    'session_key' => $sessionKey ?? 'not_found',
                    'trace' => $e->getTraceAsString()
                ]
            ];
        }
    }
}
