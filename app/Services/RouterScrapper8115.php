<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Exception;

class RouterScrapper8115
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
     * Replica la función mess_userpass() del JavaScript del router
     * XOR-ea cada carácter con 0x1f
     */
    private function mess_userpass($str)
    {
        $result = '';
        for ($i = 0; $i < strlen($str); $i++) {
            $char = $str[$i];
            $ascii = ord($char);
            $transformed = $ascii ^ 0x1f;
            $result .= chr($transformed);
        }
        return $result;
    }

    /**
     * Ejecuta el scraping del router
     */
    public function scrape()
    {
        try {
            // PASO 1: Obtener directamente el frame (te_wifi.asp)
            $frameResponse = $this->client->get('/te_wifi.asp');
            $frameHtml = (string) $frameResponse->getBody();

            if (empty($frameHtml)) {
                throw new Exception("No se pudo obtener te_wifi.asp");
            }

            // PASO 2: Extraer RX óptica directamente del JavaScript
            if (preg_match('/RX:(-?[\d\.]+)\s*dBm/', $frameHtml, $matches)) {
                $this->result['data']['rx_optica'] = $matches[1] . ' dBm';
            }
            $this->result['data']['password'] = $this->password;

            // PASO 3: Hacer login
            // Importante: El router siempre usa el usuario 'user' para esta página
            $transformedUsername = $this->mess_userpass('user');
            $transformedPassword = $this->mess_userpass($this->password);

            $loginResponse = $this->client->post('/cgi-bin/te_acceso_router.cgi', [
                'form_params' => [
                    'curWebPage' => '/te_wifi.asp',
                    'loginUsername' => $transformedUsername,
                    'loginPassword' => $transformedPassword
                ]
            ]);
            $loginStatusCode = $loginResponse->getStatusCode();
            $loginBody = (string) $loginResponse->getBody();
            $this->result['data']['login_status_code'] = $loginStatusCode;
            $this->result['data']['login_response_preview'] = substr($loginBody, 0, 300);

            // Debug: Ver cookies después del login
            $cookies = $this->cookieJar->toArray();
            $this->result['data']['cookies_after_login'] = count($cookies) > 0 ? 'yes (' . count($cookies) . ')' : 'no';

            // PASO 4: Obtener página después del login
            $authResponse = $this->client->get('/te_wifi.asp');
            $authHtml = (string) $authResponse->getBody();
            $authStatusCode = $authResponse->getStatusCode();
            $this->result['data']['auth_status_code'] = $authStatusCode;

            // Verificar si realmente estamos autenticados
            $isAuthenticated = (strpos($authHtml, 'var wifiSSIDName') !== false);
            $this->result['data']['is_authenticated'] = $isAuthenticated ? 'yes' : 'no';
            $this->result['data']['html_contains_wifi_config'] = strpos($authHtml, 'var wifiSSIDName') !== false;

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

            // PASO 5: Obtener información del WiFi 5GHz
            $frameResponse5g = $this->client->get('/te_wifi_5ghz.asp');
            $frameHtml5g = (string) $frameResponse5g->getBody();

            if (empty($frameHtml5g)) {
                throw new Exception("No se pudo obtener te_wifi_5ghz.asp");
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

            // PASO 6: Obtener información de instalación
            $frameResponsInstalacion = $this->client->get('/index_instalacion.asp');
            $frameHtmlInstalacion = (string) $frameResponsInstalacion->getBody();

            if (empty($frameHtmlInstalacion)) {
                throw new Exception("No se pudo obtener index_instalacion.asp");
            }

            // PASO 7: Hacer login en instalación con usuario "Support"
            $transformedUsernameSupport = $this->mess_userpass('Support');
            $transformedPasswordSupport = $this->mess_userpass($this->password);

            $loginInstalacionResponse = $this->client->post('/cgi-bin/te_acceso_router.cgi', [
                'form_params' => [
                    'curWebPage' => '/me_install.asp',
                    'loginUsername' => $transformedUsernameSupport,
                    'loginPassword' => $transformedPasswordSupport
                ]
            ]);

            // PASO 8: Obtener página autenticada de instalación
            $authInstalacionResponse = $this->client->get('/me_install.asp');
            $authInstalacionHtml = (string) $authInstalacionResponse->getBody();

            if (!empty($authInstalacionHtml)) {
                // Extraer Fabricante (info[0])
                if (preg_match('/info\[0\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $authInstalacionHtml, $matches)) {
                    $this->result['data']['fabricante'] = trim($matches[1]);
                }

                // Extraer Modelo (info[1])
                if (preg_match('/info\[1\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $authInstalacionHtml, $matches)) {
                    $this->result['data']['modelo_equipo'] = trim($matches[1]);
                }

                // Extraer Firmware (info[2])
                if (preg_match('/info\[2\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $authInstalacionHtml, $matches)) {
                    $this->result['data']['firmware'] = trim($matches[1]);
                }

                // Extraer Estado del Router (info[3])
                if (preg_match('/info\[3\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $authInstalacionHtml, $matches)) {
                    $this->result['data']['router_status'] = trim($matches[1]) == '1' ? 'Internet en operación' : 'Internet está sin configurar';
                }

                // Extraer Número de serie (info[4])
                if (preg_match('/info\[4\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $authInstalacionHtml, $matches)) {
                    $this->result['data']['numero_serie'] = trim($matches[1]);
                }

                // Extraer Dirección MAC (info[5])
                if (preg_match('/info\[5\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $authInstalacionHtml, $matches)) {
                    $this->result['data']['mac_address'] = trim($matches[1]);
                }

                // Extraer GPON Password
                if (preg_match('/var gponPw\s*=\s*[\'"]([^\'"]*)[\'"]/', $authInstalacionHtml, $matches)) {
                    $this->result['data']['gpon_password'] = trim($matches[1]);
                }
            }

            // Si llegamos aquí, todo salió bien
            $this->result['status'] = 'success';

        } catch (Exception $e) {
            // Si hubo algún error, guardarlo en el resultado
            $this->result['status'] = 'error';
            $this->result['error'] = $e->getMessage();
        }

        return $this->result;
    }
}
