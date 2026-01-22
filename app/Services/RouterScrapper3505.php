<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Exception;

class RouterScrapper3505
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

        // Crear el cliente HTTP con configuración para HG3505
        // Este modelo usa HTTP/0.9 en algunas respuestas
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
            'curl' => [
                CURLOPT_HTTP09_ALLOWED => 1,
            ],
        ]);
    }

    /**
     * Ejecuta el scraping del router modelo 3505
     * NOTA: Este modelo NO usa transformación XOR, las credenciales van en texto plano
     */
    public function scrape()
    {
        try {
            // PASO 1: Obtener sessionID desde la raíz
            $rootResponse = $this->client->get('/');
            $rootStatusCode = $rootResponse->getStatusCode();
            $this->result['data']['root_status_code'] = $rootStatusCode;

            // Debug: Ver cookies después del GET a raíz
            $cookies = $this->cookieJar->toArray();
            $this->result['data']['cookies_after_root'] = count($cookies) > 0 ? 'yes (' . count($cookies) . ')' : 'no';

            if (count($cookies) > 0) {
                $this->result['data']['sessionID'] = $cookies[0]['Value'];
            }

            // PASO 2: Login con usuario 'user' y la password recibida (SIN transformación XOR)
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

            // PASO 3: Obtener página WiFi 2.4GHz
            $authResponse = $this->client->get('/te_wifi.html');
            $authHtml = (string) $authResponse->getBody();
            $authStatusCode = $authResponse->getStatusCode();
            $this->result['data']['wifi_page_status_code'] = $authStatusCode;

            // Verificar si realmente estamos autenticados
            $isAuthenticated = (strpos($authHtml, 'var wifiSSIDName') !== false);
            $this->result['data']['is_authenticated'] = $isAuthenticated ? 'yes' : 'no';

            if ($isAuthenticated && !empty($authHtml)) {
                // Buscar el SSID desde la variable JavaScript
                if (preg_match('/var wifiSSIDName = htmlDecode\([\'"]([^\'"]*?)[\'"]\)/', $authHtml, $matches)) {
                    $this->result['data']['ssid'] = htmlspecialchars_decode($matches[1]);
                }

                // Buscar el canal actual
                if (preg_match('/var wifiCurrentChannel = [\'"](\d+)[\'"]/', $authHtml, $matches)) {
                    $this->result['data']['current_channel'] = $matches[1];
                }

                // Buscar contraseña WiFi
                if (preg_match('/var wpaPskKey = htmlDecode\([\'"]([^\'"]*?)[\'"]\)/', $authHtml, $matches)) {
                    $this->result['data']['wifi_password'] = htmlspecialchars_decode($matches[1]);
                }

                // Buscar tipo de autenticación
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

                // Buscar estado WiFi
                if (preg_match('/var wifiActive = [\'"]([^\'"]+)[\'"]/', $authHtml, $matches)) {
                    $this->result['data']['wifi_active'] = $matches[1] == '1' ? 'Activado' : 'Desactivado';
                }

                // Buscar canal actual desde el HTML
                if (preg_match('/<td[^>]*name="currentChannel"[^>]*>(\d+)<\/td>/', $authHtml, $matches)) {
                    $this->result['data']['current_channel_from_html'] = $matches[1];
                }
            } else {
                $this->result['data']['debug_message'] = 'No autenticado o HTML inválido. No se encontró var wifiSSIDName en el HTML.';
                $this->result['data']['auth_html_debug'] = $authHtml;
            }

            // PASO 4: Obtener información del WiFi 5GHz
            $frameResponse5g = $this->client->get('/te_wifi_5ghz.html');
            $frameHtml5g = (string) $frameResponse5g->getBody();

            if (empty($frameHtml5g)) {
                throw new Exception("No se pudo obtener te_wifi_5ghz.html");
            }

            // Extraer información del WiFi 5GHz
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

            // PASO 5: Login en Instalación (SIN transformación XOR)
            // Usuario: "Installation", Password: "Te2ef7n2c4hgu2" (fijo para todos los 3505)
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

            // PASO 6: Obtener página de instalación (install.html)
            $installResponse = $this->client->get('/install.html');
            $installHtml = (string) $installResponse->getBody();
            $installStatusCode = $installResponse->getStatusCode();
            $this->result['data']['install_page_status'] = $installStatusCode;

            // Verificar si tenemos acceso (buscar el array info[])
            $hasInstallAccess = (strpos($installHtml, 'var info = []') !== false);
            $this->result['data']['install_access'] = $hasInstallAccess ? 'yes' : 'no';

            if ($hasInstallAccess) {
                // Extraer info[0] = Fabricante
                if (preg_match('/info\[0\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $installHtml, $matches)) {
                    $this->result['data']['fabricante'] = trim($matches[1]);
                }

                // Extraer info[1] = Modelo (se le agrega RTF al principio)
                if (preg_match('/info\[1\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $installHtml, $matches)) {
                    $modelo = trim($matches[1]);
                    $this->result['data']['modelo_equipo'] = (strpos($modelo, 'RTF') !== 0) ? 'RTF' . $modelo : $modelo;
                }

                // Extraer info[2] = Firmware
                if (preg_match('/info\[2\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $installHtml, $matches)) {
                    $this->result['data']['firmware'] = trim($matches[1]);
                }

                // Extraer info[3] = Estado del Router
                if (preg_match('/info\[3\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $installHtml, $matches)) {
                    $status = trim($matches[1]);
                    $this->result['data']['router_status'] = ($status == 'Connected') ? 'Internet está configurado' : 'Internet está sin configurar';
                }

                // Extraer info[4] = Número de serie
                if (preg_match('/info\[4\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $installHtml, $matches)) {
                    $this->result['data']['numero_serie'] = trim($matches[1]);
                }

                // Extraer info[5] = Dirección MAC
                if (preg_match('/info\[5\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $installHtml, $matches)) {
                    $this->result['data']['mac_address'] = trim($matches[1]);
                }

                // Extraer info[6] = Potencia óptica (extraer solo RX)
                if (preg_match('/info\[6\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $installHtml, $matches)) {
                    $potencia = trim($matches[1]);
                    // Formato: "TX:1.942090 dBm;RX:-15.575203 dBm"
                    if (preg_match('/RX:([-\d.]+)/', $potencia, $rxMatch)) {
                        $this->result['data']['potencia_optica'] = $rxMatch[1] . ' dBm';
                    } else {
                        $this->result['data']['potencia_optica'] = $potencia;
                    }
                }

                // Extraer GPON Password (en hexadecimal, convertir a texto)
                if (preg_match('/var gponPw\s*=\s*[\'"]([^\'"]+)[\'"]/', $installHtml, $matches)) {
                    $gponHex = trim($matches[1]);
                    // Convertir de hex a string (quitar los 00 al final)
                    $gponHex = rtrim($gponHex, '0');
                    if (strlen($gponHex) % 2 != 0) $gponHex .= '0';
                    $gponText = '';
                    for ($i = 0; $i < strlen($gponHex); $i += 2) {
                        $gponText .= chr(hexdec(substr($gponHex, $i, 2)));
                    }
                    $this->result['data']['gpon_password'] = $gponText;
                }
            } else {
                // Guardar preview del HTML para debug
                $this->result['data']['install_html_preview'] = substr($installHtml, 0, 500);
            }

            // Si llegamos aquí, todo salió bien
            $this->result['status'] = 'success';

        } catch (Exception $e) {
            // Si hubo algún error, guardarlo en el resultado
            $this->result['status'] = 'error';
            $this->result['error'] = $e->getMessage();
            $this->result['data']['error_trace'] = $e->getTraceAsString();
        }

        return $this->result;
    }
}
