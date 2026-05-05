<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Exception;

class RouterScrapper2841
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
        // - sin username (solo password) para WiFi
        // - 'Support' para instalación
        $this->password = $password;

        $this->result = [
            'ip' => $ip,
            'modelo' => $modelo,
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => 'unknown',
            'data' => []
        ];

        $this->cookieJar = new CookieJar();

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
     * Replica la lógica JS del router: hex_md5(password + ':' + sid).
     * El sid es un nonce que viene en el HTML del login (var sid = '...').
     */
    private function hashPassword($password, $sid)
    {
        return md5($password . ':' . $sid);
    }

    /**
     * Extrae el valor del nonce sid (var sid = 'XXXXXXXX') del HTML.
     * Devuelve null si no lo encuentra.
     */
    private function extractSid($html)
    {
        if (preg_match('/var\s+sid\s*=\s*[\'"]([a-f0-9]+)[\'"]/i', $html, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Ejecuta el scraping del router modelo 2841 (Mitrastar GPT-2841GX4X5-SV).
     * Flujo:
     *   1) GET /cgi-bin/logIn_mhs.cgi              → form login + extraer sid
     *   2) POST /cgi-bin/logIn_mhs.cgi             (syspasswd = md5(pass+':'+sid))
     *   3) GET /cgi-bin/wifi.cgi                   → SSID/pass/canal 2.4GHz (inputs hidden + var JS)
     *   4) GET /cgi-bin/wifi5g.cgi                 → SSID/pass/canal 5GHz
     *   5) GET /Instalacion                        → form login Support + extraer sid
     *   6) POST /Instalacion                       (sysusername=Support, syspasswd=md5)
     *   7) GET /cgi-bin/Instalacion_ontpw.cgi      → modelo, firmware, MAC, dBm (spans class="sub")
     */
    public function scrape()
    {
        try {
            // PASO 1: Obtener form de login y extraer el sid
            $loginPageResponse = $this->client->get('/cgi-bin/logIn_mhs.cgi');
            $loginPageHtml = (string) $loginPageResponse->getBody();
            $this->result['data']['root_status_code'] = $loginPageResponse->getStatusCode();

            if (empty($loginPageHtml)) {
                throw new Exception("No se pudo obtener /cgi-bin/logIn_mhs.cgi");
            }

            $sid = $this->extractSid($loginPageHtml);
            $this->result['data']['login_sid'] = $sid ?? 'not_found';

            if (!$sid) {
                throw new Exception("No se pudo extraer el nonce 'sid' del HTML de login");
            }

            // PASO 2: Login con password hasheada (md5(password + ':' + sid))
            $hashedPassword = $this->hashPassword($this->password, $sid);

            $loginResponse = $this->client->post('/cgi-bin/logIn_mhs.cgi', [
                'form_params' => [
                    'sessionKey' => '',
                    'submitValue' => '1',
                    'fake_syspasswd' => '',
                    'syspasswd_1' => '',
                    'syspasswd' => $hashedPassword,
                    'leaveBlur' => '0',
                    'Submit' => 'Entrar'
                ],
                'headers' => [
                    'Referer' => "http://{$this->ip}/cgi-bin/logIn_mhs.cgi",
                ]
            ]);
            $this->result['data']['login_status_code'] = $loginResponse->getStatusCode();
            $cookies = $this->cookieJar->toArray();
            $this->result['data']['cookies_after_login'] = count($cookies) > 0 ? 'yes (' . count($cookies) . ')' : 'no';

            // PASO 3: Página WiFi 2.4GHz
            // URL real: /cgi-bin/mhs.cgi (el menú "WiFi" apunta acá; /cgi-bin/wifi.cgi
            // no existe y devuelve 403 con mensaje engañoso "local referer").
            $wifi24Response = $this->client->get('/cgi-bin/mhs.cgi', [
                'headers' => [
                    'Referer' => "http://{$this->ip}/cgi-bin/logIn_mhs.cgi",
                ]
            ]);
            $wifi24Html = (string) $wifi24Response->getBody();
            $this->result['data']['wifi_page_status_code'] = $wifi24Response->getStatusCode();

            // Verificamos autenticación buscando los inputs hidden característicos
            $isAuthenticated = (strpos($wifi24Html, 'id="WPAPSK_Key"') !== false || strpos($wifi24Html, 'id="SSID"') !== false);
            $this->result['data']['is_authenticated'] = $isAuthenticated ? 'yes' : 'no';

            if ($isAuthenticated && !empty($wifi24Html)) {
                // SSID 2.4GHz desde input hidden: <input type="hidden" name="SSID" id="SSID" value="...">
                if (preg_match('/<input[^>]*id="SSID"[^>]*value="([^"]*)"/', $wifi24Html, $matches)) {
                    $this->result['data']['ssid'] = htmlspecialchars_decode($matches[1]);
                }

                // Password 2.4GHz desde input hidden WPAPSK_Key
                if (preg_match('/<input[^>]*id="WPAPSK_Key"[^>]*value="([^"]*)"/', $wifi24Html, $matches)) {
                    $this->result['data']['wifi_password'] = htmlspecialchars_decode($matches[1]);
                }

                // Canal actual desde var JS: var CurrentChannel = '11';
                if (preg_match('/var\s+CurrentChannel\s*=\s*[\'"](\d+)[\'"]/', $wifi24Html, $matches)) {
                    $this->result['data']['current_channel'] = $matches[1];
                }

                // Canal desde div renderizado (si está): <div id="cur_wifi_channel">11</div>
                if (preg_match('/<div[^>]*id="cur_wifi_channel"[^>]*>(\d+)<\/div>/', $wifi24Html, $matches)) {
                    $this->result['data']['current_channel_from_html'] = $matches[1];
                }

                $this->result['data']['wifi_active'] = 'Activado';
            } else {
                $this->result['data']['debug_message'] = 'No autenticado o HTML inválido. No se encontraron inputs SSID/WPAPSK_Key.';
                $this->result['data']['wifi_html_preview'] = substr($wifi24Html, 0, 500);
            }

            // PASO 4: Página WiFi 5GHz (referer = mhs.cgi simula el click en "WiFi Plus")
            $wifi5gResponse = $this->client->get('/cgi-bin/wifi5g.cgi', [
                'headers' => [
                    'Referer' => "http://{$this->ip}/cgi-bin/mhs.cgi",
                ]
            ]);
            $wifi5gHtml = (string) $wifi5gResponse->getBody();

            if (empty($wifi5gHtml)) {
                throw new Exception("No se pudo obtener /cgi-bin/wifi5g.cgi");
            }

            if (preg_match('/<input[^>]*id="SSID"[^>]*value="([^"]*)"/', $wifi5gHtml, $matches)) {
                $this->result['data']['ssid_5g'] = htmlspecialchars_decode($matches[1]);
            }

            if (preg_match('/<input[^>]*id="WPAPSK_Key"[^>]*value="([^"]*)"/', $wifi5gHtml, $matches)) {
                $this->result['data']['wifi_password_5g'] = htmlspecialchars_decode($matches[1]);
            }

            if (preg_match('/var\s+CurrentChannel\s*=\s*[\'"](\d+)[\'"]/', $wifi5gHtml, $matches)) {
                $this->result['data']['current_channel_5g'] = $matches[1];
            }

            $this->result['data']['wifi_active_5g'] = 'Activado';

            // PASO 5: Página de instalación (form login Support)
            $instalacionPageResponse = $this->client->get('/Instalacion');
            $instalacionPageHtml = (string) $instalacionPageResponse->getBody();

            $sidInstall = $this->extractSid($instalacionPageHtml);
            $this->result['data']['install_sid'] = $sidInstall ?? 'not_found';

            if (!$sidInstall) {
                throw new Exception("No se pudo extraer el nonce 'sid' del HTML de instalación");
            }

            // PASO 6: Login en instalación con usuario 'Support'
            $hashedInstallPassword = $this->hashPassword($this->password, $sidInstall);

            $loginInstallResponse = $this->client->post('/Instalacion', [
                'form_params' => [
                    'sessionKey' => '',
                    'submitValue' => '1',
                    'sysusername' => 'Support',
                    'syspasswd_1' => '',
                    'syspasswd' => $hashedInstallPassword,
                    'leaveBlur' => '0',
                    'Submit' => 'Entrar'
                ],
                'headers' => [
                    'Referer' => "http://{$this->ip}/Instalacion",
                ]
            ]);
            $this->result['data']['install_login_status'] = $loginInstallResponse->getStatusCode();

            // PASO 7: Página de información de instalación (modelo, firmware, MAC, dBm)
            // Referer = la página de login de instalación (simula click post-login)
            $installResponse = $this->client->get('/cgi-bin/Instalacion_ontpw.cgi', [
                'headers' => [
                    'Referer' => "http://{$this->ip}/Instalacion",
                ]
            ]);
            $installHtml = (string) $installResponse->getBody();
            $this->result['data']['install_page_status'] = $installResponse->getStatusCode();

            $hasInstallAccess = (strpos($installHtml, 'class="sub"') !== false && strpos($installHtml, 'Modelo:') !== false);
            $this->result['data']['install_access'] = $hasInstallAccess ? 'yes' : 'no';

            if ($hasInstallAccess) {
                // Patrón común: <span class="sub">CAMPO: <br>VALOR</span>
                // preg_match (sin /g) toma SOLO la primera ocurrencia, que corresponde al
                // bloque del modelo real renderizado por el server (los demás bloques son
                // alternativas para otros hardwareModel y vienen en el HTML pero ocultos).

                // Modelo: <span class="sub">Modelo: <br>GPT-2841GX4X5-SV</span>
                if (preg_match('/<span class="sub">Modelo:\s*<br>([^<]+)<\/span>/', $installHtml, $matches)) {
                    $this->result['data']['modelo_equipo'] = trim($matches[1]);
                }

                // Firmware: <span class="sub">Firmware: <br>GL_g2.11_100XNQ0b21</span>
                if (preg_match('/<span class="sub">Firmware:\s*<br>([^<]+)<\/span>/', $installHtml, $matches)) {
                    $this->result['data']['firmware'] = trim($matches[1]);
                }

                // Fabricante: <span class="sub">Fabricante: <br>Mitrastar</span>
                if (preg_match('/<span class="sub">Fabricante:\s*<br>([^<]+)<\/span>/', $installHtml, $matches)) {
                    $this->result['data']['fabricante'] = trim($matches[1]);
                }

                // Direcciones MAC: <span class="sub">Direcciones MAC: <br>2C:96:82:31:48:B8</span>
                // Para este modelo el "Nº de serie" es la MAC SIN los dos puntos.
                if (preg_match('/<span class="sub">Direcciones MAC:\s*<br>([^<]+)<\/span>/u', $installHtml, $matches)) {
                    $macConDosPuntos = trim($matches[1]);
                    $this->result['data']['mac_address'] = $macConDosPuntos;
                    // numero_serie = MAC sin ":"
                    $this->result['data']['numero_serie'] = str_replace(':', '', $macConDosPuntos);
                }

                // Potencia óptica: <span class="sub">Potencia óptica recibida[dBm]: <br>-40.00</span>
                if (preg_match('/<span class="sub">Potencia [oó]ptica recibida\[dBm\]:\s*<br>([^<]+)<\/span>/u', $installHtml, $matches)) {
                    $potencia = trim($matches[1]);
                    // Si trae "TX:...;RX:..." extraer RX; si es número plano, agregar dBm
                    if (preg_match('/RX:(-?[\d.]+)/', $potencia, $rxMatch)) {
                        $this->result['data']['potencia_optica'] = $rxMatch[1] . ' dBm';
                    } else {
                        $this->result['data']['potencia_optica'] = $potencia . (strpos($potencia, 'dBm') === false ? ' dBm' : '');
                    }
                }

                // Estado del router (Conectado/Sin conexión) - hay 2 li, uno con display:none y otro visible.
                // El visible es el real. preg_match toma el primero que no tenga display:none... mejor ambos:
                if (preg_match('/<li[^>]*style="(?!display:none)[^"]*"[^>]*id="(?:connect|disconnect)"[^>]*><span class="sub">Router:\s*<br>([^<]+)<\/span>/', $installHtml, $matches)) {
                    $this->result['data']['router_status'] = trim($matches[1]);
                }

                // GPON Password actual:
                // El input SLID viene VACÍO en el HTML crudo. El JS lo rellena en
                // runtime desde la variable JS `var SLIDHexValue = 'XXXXXXXXXX...';`
                // que contiene el GPON en hex (cada char ASCII = 2 dígitos hex,
                // padded con '0' hasta 20 chars). Decodificamos hex → ASCII y
                // cortamos en `00` (padding).
                if (preg_match('/var\s+SLIDHexValue\s*=\s*[\'"]([0-9a-fA-F]+)[\'"]/', $installHtml, $matches)) {
                    $slidHex = trim($matches[1]);
                    $slidText = '';
                    for ($i = 0; $i < strlen($slidHex); $i += 2) {
                        $hexPair = substr($slidHex, $i, 2);
                        if ($hexPair === '00') break; // padding
                        $slidText .= chr(hexdec($hexPair));
                    }
                    $this->result['data']['gpon_password'] = $slidText;
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
     * Login Support → GET /cgi-bin/Instalacion_ontpw.cgi → spans class="sub".
     */
    public function sacarDbm()
    {
        try {
            // Inicializar sesión + login Support
            $instalacionPageResponse = $this->client->get('/Instalacion');
            $instalacionPageHtml = (string) $instalacionPageResponse->getBody();

            $sidInstall = $this->extractSid($instalacionPageHtml);
            if (!$sidInstall) {
                throw new Exception("No se pudo extraer el nonce 'sid'");
            }

            $hashedInstallPassword = $this->hashPassword($this->password, $sidInstall);
            $this->client->post('/Instalacion', [
                'form_params' => [
                    'sessionKey' => '',
                    'submitValue' => '1',
                    'sysusername' => 'Support',
                    'syspasswd_1' => '',
                    'syspasswd' => $hashedInstallPassword,
                    'leaveBlur' => '0',
                    'Submit' => 'Entrar'
                ],
                'headers' => [
                    'Referer' => "http://{$this->ip}/Instalacion",
                ]
            ]);

            $installResponse = $this->client->get('/cgi-bin/Instalacion_ontpw.cgi', [
                'headers' => [
                    'Referer' => "http://{$this->ip}/Instalacion",
                ]
            ]);
            $installHtml = (string) $installResponse->getBody();

            $hasInstallAccess = (strpos($installHtml, 'class="sub"') !== false);

            if ($hasInstallAccess) {
                if (preg_match('/<span class="sub">Potencia [oó]ptica recibida\[dBm\]:\s*<br>([^<]+)<\/span>/u', $installHtml, $matches)) {
                    $potencia = trim($matches[1]);
                    if (preg_match('/RX:(-?[\d.]+)/', $potencia, $rxMatch)) {
                        $this->result['data']['potencia_optica'] = $rxMatch[1] . ' dBm';
                    } else {
                        $this->result['data']['potencia_optica'] = $potencia . (strpos($potencia, 'dBm') === false ? ' dBm' : '');
                    }
                }
            } else {
                $this->result['data']['error'] = 'No se pudo acceder a la página de instalación';
            }

            $this->result['status'] = 'success';

        } catch (Exception $e) {
            $this->result['status'] = 'error';
            $this->result['error'] = $e->getMessage();
        }

        return $this->result;
    }

    /**
     * Configura el identificador GPON (ONT ID/SLID) en el router.
     * Login Support → POST /cgi-bin/Instalacion_ontpw.cgi con SLID=$gpon.
     */
    public function configGpon($gpon)
    {
        try {
            // Login Support
            $instalacionPageResponse = $this->client->get('/Instalacion');
            $instalacionPageHtml = (string) $instalacionPageResponse->getBody();

            $sidInstall = $this->extractSid($instalacionPageHtml);
            if (!$sidInstall) {
                throw new Exception("No se pudo extraer el nonce 'sid' del login de instalación");
            }

            $hashedInstallPassword = $this->hashPassword($this->password, $sidInstall);
            $loginResponse = $this->client->post('/Instalacion', [
                'form_params' => [
                    'sessionKey' => '',
                    'submitValue' => '1',
                    'sysusername' => 'Support',
                    'syspasswd_1' => '',
                    'syspasswd' => $hashedInstallPassword,
                    'leaveBlur' => '0',
                    'Submit' => 'Entrar'
                ],
                'headers' => [
                    'Referer' => "http://{$this->ip}/Instalacion",
                ]
            ]);

            if ($loginResponse->getStatusCode() !== 200) {
                throw new Exception("Error al autenticar en el portal de instalación.");
            }

            // POST a /cgi-bin/Instalacion_ontpw.cgi con SLID en HEX padded.
            // El JS del router (función checkRegID) convierte el SLID ASCII a hex,
            // padding con '0' hasta 20 chars y uppercase ANTES de enviar el form.
            // Réplica de a2hex(regId) + padding + toUpperCase():
            //   "5354267" → "35333534323637" → "35333534323637000000"
            $gponAscii = (string) $gpon;
            $slidHex = '';
            for ($i = 0; $i < strlen($gponAscii); $i++) {
                $slidHex .= sprintf('%02X', ord($gponAscii[$i]));
            }
            while (strlen($slidHex) < 20) {
                $slidHex .= '0';
            }

            $body = http_build_query([
                'SLID' => $slidHex,
                'submitValue' => '1',
                'Submit' => 'Aceptar'
            ]);

            $configResponse = $this->client->post('/cgi-bin/Instalacion_ontpw.cgi', [
                'body' => $body,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Referer' => "http://{$this->ip}/cgi-bin/Instalacion_ontpw.cgi",
                    'Content-Length' => strlen($body),
                ],
                'expect' => false,
            ]);

            return [
                'status' => 'success',
                'message' => 'Configuración GPON enviada correctamente',
                'debug' => [
                    'input_gpon' => $gpon,
                    'slid_hex_sent' => $slidHex,
                    'http_status' => $configResponse->getStatusCode()
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
     * Resetea el identificador GPON (ONT ID/SLID) del router a 000000.
     */
    public function resetConfigGpon()
    {
        return $this->configGpon('000000');
    }
}
