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
            // PASO 1: Obtener página de login para extraer el SID
            $loginPageResponse = $this->client->get('/');
            $loginPageHtml = (string) $loginPageResponse->getBody();

            // Extraer potencia óptica si está visible en el login (visto en snippet)
            // <b>Rx óptica:</b> -16.82 dBm
            if (preg_match('/Rx óptica:<\/b>\s*([-\d.]+)\s*dBm/i', $loginPageHtml, $matches)) {
                $this->result['data']['potencia_optica'] = $matches[1] . ' dBm';
            }

            // Extraer el SID: var sid = 'XXXXXXXX';
            if (!preg_match("/(?:var\s+)?sid\s*=\s*['\"]([a-f0-9]+)['\"]/i", $loginPageHtml, $matches)) {
                throw new Exception("No se pudo obtener el SID desde la página de login del 2541");
            }
            $sid = $matches[1];
            $this->result['data']['sid'] = $sid;

            // PASO 2: Login
            // passwd = hex_md5(sid + ":" + password)
            // string = "user:" + passwd
            // encodedData = base64.encode(string)
            $encryptedPassword = md5($sid . ":" . $this->password);
            $authString = "user:" . $encryptedPassword;
            $sessionKey = base64_encode($authString);

            $loginResponse = $this->client->post('/login-login.cgi', [
                'form_params' => [
                    'sessionKey'  => $sessionKey,
                    'pass'        => '',
                    'acceptLogin' => 'Entrar'
                ]
            ]);

            // PASO 3: Extracción de WiFi 2.4G (/mhs.html)
            $wifiResponse = $this->client->get('/mhs.html');
            $wifiHtml = (string) $wifiResponse->getBody();
            
            // Guardar para debug
            file_put_contents(storage_path('logs/router_2541_debug_24g.html'), $wifiHtml);

            $isAuthenticated = (strpos($wifiHtml, 'ssidname') !== false);
            $this->result['data']['is_authenticated'] = $isAuthenticated ? 'yes' : 'no';

            if ($isAuthenticated) {
                // SSID: Puede estar en el input o en variables JS (tmp, ssid)
                $ssid = $this->extractInputValue($wifiHtml, 'ssidname')
                     ?: $this->extractJSVariable($wifiHtml, 'ssid')
                     ?: $this->extractJSVariable($wifiHtml, 'tmp');
                if (!empty($ssid)) {
                    $this->result['data']['ssid'] = $ssid;
                }

                // Password: En este modelo está en variables JS
                // var securitypassWPA2="AD8E4A98B941";
                $wifiPass = $this->extractJSVariable($wifiHtml, 'securitypassWPA2') 
                         ?: $this->extractJSVariable($wifiHtml, 'securitypassWEP')
                         ?: $this->extractInputValue($wifiHtml, 'wifiPass');
                if (!empty($wifiPass)) {
                    $this->result['data']['wifi_password'] = $wifiPass;
                }

                // Canal: <div class="tabInput black2" id="cur_wifi_channel">11</div>
                // También está en var CurrentChannel="11";
                $canal = $this->extractJSVariable($wifiHtml, 'CurrentChannel') 
                      ?: $this->extractElementContent($wifiHtml, 'cur_wifi_channel');
                if ($canal !== '') {
                    $this->result['data']['current_channel'] = $canal;
                }
            }

            // PASO 4: Extracción de WiFi 5G (WiFi Plus - /wifi5g.html)
            $wifi5gResponse = $this->client->get('/wifi5g.html');
            $wifi5gHtml = (string) $wifi5gResponse->getBody();
            
            // Guardar para debug
            file_put_contents(storage_path('logs/router_2541_debug_5g.html'), $wifi5gHtml);

            if (strpos($wifi5gHtml, 'ssidname') !== false || strpos($wifi5gHtml, 'getSsidName') !== false) {
                // SSID 5G
                $ssid5g = $this->extractInputValue($wifi5gHtml, 'ssidname')
                       ?: $this->extractJSVariable($wifi5gHtml, 'ssid')
                       ?: $this->extractJSVariable($wifi5gHtml, 'tmp');
                if (!empty($ssid5g)) {
                    $this->result['data']['ssid_5g'] = $ssid5g;
                }
                
                $wifiPass5g = $this->extractJSVariable($wifi5gHtml, 'securitypassWPA2') 
                           ?: $this->extractJSVariable($wifi5gHtml, 'securitypassWEP')
                           ?: $this->extractInputValue($wifi5gHtml, 'wifiPass');
                if (!empty($wifiPass5g)) {
                    $this->result['data']['wifi_password_5g'] = $wifiPass5g;
                }

                $canal5g = $this->extractJSVariable($wifi5gHtml, 'CurrentChannel')
                        ?: $this->extractElementContent($wifi5gHtml, 'cur_wifi_channel');
                if ($canal5g !== '') {
                    $this->result['data']['current_channel_5g'] = $canal5g;
                }
            }

            // PASO 4.5: Cerrar sesión del login principal antes de ir a Instalación
            // Si no cerramos sesión, /Instalacion redirige a /mhs.html
            $this->client->get('/logout.cmd');

            // PASO 5: Página de Instalación (ONT/SLID/MAC)
            if ($this->loginInstallation()) {
                $ontResponse = $this->client->get('/installation_ontpw.html');
                $ontHtml = (string) $ontResponse->getBody();

                // Guardar para debug inicial
                file_put_contents(storage_path('logs/router_2541_debug_inst.html'), $ontHtml);

                // MAC/Número de Serie: <li_p><span class="sub">Direcciones MAC: <br>cc:d4:a1:60:fd:47</span></li_p>
                if (preg_match('/Direcciones MAC:.*?<br>\s*([a-fA-F0-9:]+)/is', $ontHtml, $m)) {
                    $mac = trim($m[1]);
                    $this->result['data']['mac_address'] = $mac;
                    // Número de serie sin ":" y en mayúsculas → CCD4A160FD47
                    $this->result['data']['numero_serie'] = strtoupper(str_replace(':', '', $mac));
                }

                // GPON Password: var gponPassword = '5483613';
                $gponPassword = $this->extractJSVariable($ontHtml, 'gponPassword');
                if ($gponPassword !== '' && $gponPassword !== '1234567890') {
                    $this->result['data']['gpon_password'] = $gponPassword;
                }

                // SessionKey para configuración GPON (lo guardamos para usarlo después)
                if (preg_match('/sessionKey\.value\s*=\s*(\d+)/', $ontHtml, $m)) {
                    $this->result['data']['session_key_gpon'] = $m[1];
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
     * POST a instalacion.cmd con gponPassword, sessionKey y action
     */
    public function configGpon($gpon)
    {
        try {
            // Validar formato del GPON (6-10 caracteres)
            if (strlen($gpon) < 6 || strlen($gpon) > 10) {
                throw new Exception("El GPON debe tener entre 6 y 10 caracteres.");
            }

            // Cerrar cualquier sesión previa antes de ir a Instalación
            $this->client->get('/logout.cmd');

            if (!$this->loginInstallation()) {
                throw new Exception("No se pudo entrar a la página de instalación.");
            }

            // Obtener la página para extraer el sessionKey
            $ontResponse = $this->client->get('/installation_ontpw.html');
            $ontHtml = (string) $ontResponse->getBody();

            // Extraer sessionKey: document.ontid.sessionKey.value = 1906789459;
            $sessionKey = '';
            if (preg_match('/sessionKey\.value\s*=\s*(\d+)/', $ontHtml, $m)) {
                $sessionKey = $m[1];
            }

            // Enviar configuración GPON a instalacion.cmd
            $response = $this->client->post('/instalacion.cmd', [
                'form_params' => [
                    'gponPassword' => $gpon,
                    'sessionKey'   => $sessionKey,
                    'action'       => '0'
                ]
            ]);

            $responseHtml = (string) $response->getBody();
            file_put_contents(storage_path('logs/router_2541_config_gpon_response.html'), $responseHtml);

            if ($response->getStatusCode() === 200) {
                return [
                    'status' => 'success',
                    'message' => 'GPON configurado correctamente.',
                    'gpon' => $gpon
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
     * Login en el menú de Instalador (ruta /Instalacion)
     * Flujo: GET /Instalacion -> extraer SID -> POST login-advance.cgi
     */
    private function loginInstallation(): bool
    {
        // PASO 1: Obtener página de login de instalación para extraer el SID
        $response = $this->client->get('/Instalacion');
        $html = (string) $response->getBody();

        // Guardar para debug
        file_put_contents(storage_path('logs/router_2541_login_instalacion.html'), $html);

        // Extraer el SID: var sid = "426d7588";
        if (preg_match("/var\s+sid\s*=\s*['\"]([a-f0-9]+)['\"]/i", $html, $matches)) {
            $sid = $matches[1];

            // PASO 2: Calcular credenciales
            // passwd = hex_md5(sid + ":" + password)
            // string = "Support:" + passwd
            // encodedData = base64.encode(string)
            $encryptedPass = md5($sid . ":" . $this->password);
            $authString = "Support:" . $encryptedPass;
            $sessionKey = base64_encode($authString);

            // PASO 3: Enviar login a login-advance.cgi
            $loginResponse = $this->client->post('/login-advance.cgi', [
                'form_params' => [
                    'sessionKey' => $sessionKey,
                    'user'       => '',
                    'pass'       => ''
                ]
            ]);

            // Verificar si el login fue exitoso visitando la página de instalación
            $checkResponse = $this->client->get('/installation_ontpw.html');
            $checkHtml = (string) $checkResponse->getBody();

            // Si contiene "Identificación ONT" o "gponPassword", el login fue exitoso
            return (strpos($checkHtml, 'gponPassword') !== false || strpos($checkHtml, 'Identificación ONT') !== false);
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

    private function extractElementContent(string $html, string $elementId): string
    {
        $escaped = preg_quote($elementId, '/');
        if (preg_match('/<[^>]*id=["\']' . $escaped . '["\'][^>]*>([^<]*)</', $html, $m)) {
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
