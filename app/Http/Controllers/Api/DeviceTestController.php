<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NetworkManagerFactory;
use App\Services\RouterScrapper2541;
use App\Services\RouterScrapper2741;
use App\Services\RouterScrapper3505;
use App\Services\RouterScrapper8115;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class DeviceTestController extends Controller
{
    private $gponConfigResult = null;

    /**
     * Extraer datos del equipo (Scraping)
     * Opcionalmente puede configurar el GPON antes de scrapear
     */
    public function scrapeData(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'modelo' => 'required|string',
            'password' => 'required|string',
            'gpon' => 'nullable|string',
        ]);

        $ip = $request->input('ip');
        $modelo = $request->input('modelo');
        $password = $request->input('password');
        $username = $request->input('username', 'user');
        $gpon = $request->input('gpon');

        Log::info("Iniciando scrap para modelo {$modelo} en IP {$ip}" . ($gpon ? " con GPON {$gpon}" : ""));

        try {
            $scrapper = null;
            switch (true) {
                case str_contains($modelo, '2741'):
                    $scrapper = new RouterScrapper2741($ip, $username, $password, $modelo);
                    break;
                case str_contains($modelo, '2541'):
                    $scrapper = new RouterScrapper2541($ip, $username, $password, $modelo);
                    break;
                case str_contains($modelo, '3505'):
                    $scrapper = new RouterScrapper3505($ip, $username, $password, $modelo);
                    break;
                case str_contains($modelo, '8115'):
                    $scrapper = new RouterScrapper8115($ip, $username, $password, $modelo);
                    break;
                // case str_contains($modelo, 'XXXX'):
                //     $scrapper = new RouterScrapperXXXX($ip, $username, $password, $modelo);
                //     break;
                default:
                    return response()->json([
                        'success' => false,
                        'message' => "Modelo {$modelo} no soportado para scraping"
                    ], 400);
            }

            // Si se envía el parámetro gpon, configurar antes de scrapear
           /* if (!empty($gpon)) {
                $configResult = $scrapper->configGpon($gpon);

                // Almacenar el resultado de la configuración
                $this->gponConfigResult = $configResult;

                // Si la configuración fue exitosa, esperamos 5 segundos
                // para que el router procese el cambio y se estabilice
                if ($configResult['status'] === 'success') {
                    sleep(8);
                }
            }*/

            // Ejecutar el scraping con retry si hay GPON configurado
            $maxRetries = !empty($gpon) ? 2 : 0;
            $retryDelays = [5, 8]; // Segundos de espera entre reintentos
            $result = null;
            $attempt = 0;

            do {
                $attempt++;
                $result = $scrapper->scrape();

                // Si no hay GPON o se autenticó correctamente, salir del loop
                if (empty($gpon) || ($result['data']['is_authenticated'] ?? '') !== 'no') {
                    break;
                }

                // Si no se autenticó y quedan reintentos, esperar y reintentar
                if ($attempt <= $maxRetries) {
                    $delay = $retryDelays[$attempt - 1] ?? 10;
                    Log::info("Scrape intento {$attempt}: is_authenticated=no. Reintentando en {$delay}s...");
                    sleep($delay);

                    // Crear nueva instancia del scrapper para una sesión limpia
                    switch (true) {
                        case str_contains($modelo, '2741'):
                            $scrapper = new RouterScrapper2741($ip, $username, $password, $modelo);
                            break;
                        case str_contains($modelo, '2541'):
                            $scrapper = new RouterScrapper2541($ip, $username, $password, $modelo);
                            break;
                        case str_contains($modelo, '3505'):
                            $scrapper = new RouterScrapper3505($ip, $username, $password, $modelo);
                            break;
                        case str_contains($modelo, '8115'):
                            $scrapper = new RouterScrapper8115($ip, $username, $password, $modelo);
                            break;
                    }
                }
            } while ($attempt <= $maxRetries);

            // Agregar info de reintentos al resultado
            if ($attempt > 1) {
                $result['retry_info'] = [
                    'total_attempts' => $attempt,
                    'authenticated' => ($result['data']['is_authenticated'] ?? '') !== 'no',
                ];
                Log::info("Scrape completado después de {$attempt} intentos. Autenticado: " . (($result['data']['is_authenticated'] ?? '') !== 'no' ? 'sí' : 'no'));
            }

            // Si hubo configuración de GPON, verificar y añadir resultado
            if (isset($this->gponConfigResult)) {
                $scrapedGpon = $result['data']['gpon_password'] ?? '';

                if (!empty($gpon) && $scrapedGpon === (string)$gpon) {
                    // Si coinciden, marcamos como EXITOSA y VERIFICADA
                    $this->gponConfigResult['status'] = 'success';
                    $this->gponConfigResult['message'] = 'GPON configurado y verificado correctamente.';
                    $this->gponConfigResult['verified'] = true;
                    unset($this->gponConfigResult['error']);
                } elseif (!empty($gpon) && $this->gponConfigResult['status'] === 'success') {
                    // Si decía success pero no coinciden
                    $this->gponConfigResult['verified'] = false;
                    $this->gponConfigResult['verification_note'] = "Configuración enviada, pero el valor leído ({$scrapedGpon}) no coincide con el enviado.";
                }

                $result['gpon_config_result'] = $this->gponConfigResult;
            }

            return response()->json([
                'success' => $result['status'] === 'success',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error("Error en scrapeData: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Error al extraer datos: " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Testear conectividad Ethernet e Internet
     */
    public function testEthernet(Request $request)
    {
        Log::info("Iniciando test de internet por Ethernet");
        
        $networkManager = NetworkManagerFactory::create();
        
        try {
            $result = $networkManager->testInternetConnectivity();
            
            return response()->json([
                'success' => true,
                'internet_connection' => $result['internet_connection'],
                'details' => $result
            ]);
        } catch (\Exception $e) {
            Log::error("Error en testEthernet: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Error en test de ethernet: " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Testear redes WiFi (2.4GHz y 5GHz)
     * Este proceso es asíncrono o de larga duración
     */
    public function testWifi(Request $request)
    {
        $request->validate([
            'ssid' => 'required|string',
            'wifi_password' => 'required|string',
            'ssid_5g' => 'nullable|string',
            'wifi_password_5g' => 'nullable|string',
        ]);

        $ssid24 = $request->input('ssid');
        $password24 = $request->input('wifi_password');
        $ssid5g = $request->input('ssid_5g');
        $password5g = $request->input('wifi_password_5g');
        
        // Generar ID para seguimiento si se desea, pero aquí lo haremos directo
        // En Windows, desconectar Ethernet para probar WiFi puede ser arriesgado si se hace vía API
        // pero el usuario dice que es 1 a 1 en Windows, así que procedemos.

        Log::info("Iniciando test de WiFi: {$ssid24} / {$ssid5g}");

        $networkManager = NetworkManagerFactory::create();
        $results = [
            'timestamp' => now()->toDateTimeString(),
            'wifi_24ghz' => null,
            'wifi_5ghz' => null,
        ];

        try {
            // 1. Desactivar Ethernet
            Log::info("Desactivando Ethernet para test de inalámbricos...");
            $disableEth = $networkManager->disableEthernet();
            
            if (!$disableEth['success']) {
                throw new \Exception("No se pudo desactivar Ethernet: " . $disableEth['output']);
            }

            sleep(2);

            // 2. Probar 2.4GHz
            Log::info("Conectando a WiFi 2.4GHz: {$ssid24}");
            $connect24 = $networkManager->connectToWifi($ssid24, $password24);
            
            if ($connect24['status']['success'] ?? false) {
                Log::info("WiFi 2.4GHz conectado, testeando internet...");
                $internet24 = $networkManager->testInternetConnectivity();
                $results['wifi_24ghz'] = [
                    'ssid' => $ssid24,
                    'connected' => true,
                    'internet' => $internet24['internet_connection'],
                    'details' => $internet24
                ];
            } else {
                $results['wifi_24ghz'] = [
                    'ssid' => $ssid24,
                    'connected' => false,
                    'error' => $connect24['connect']['output'] ?? 'Fallo de conexión'
                ];
            }

            $networkManager->disconnectWifi();
            $networkManager->removeWifiProfile($ssid24);
            sleep(2);

            // 3. Probar 5GHz si existe
            if ($ssid5g && $password5g) {
                Log::info("Conectando a WiFi 5GHz: {$ssid5g}");
                $connect5g = $networkManager->connectToWifi($ssid5g, $password5g);
                
                if ($connect5g['status']['success'] ?? false) {
                    Log::info("WiFi 5GHz conectado, testeando internet...");
                    $internet5g = $networkManager->testInternetConnectivity();
                    $results['wifi_5ghz'] = [
                        'ssid' => $ssid5g,
                        'connected' => true,
                        'internet' => $internet5g['internet_connection'],
                        'details' => $internet5g
                    ];
                } else {
                    $results['wifi_5ghz'] = [
                        'ssid' => $ssid5g,
                        'connected' => false,
                        'error' => $connect5g['connect']['output'] ?? 'Fallo de conexión'
                    ];
                }
                
                $networkManager->disconnectWifi();
                $networkManager->removeWifiProfile($ssid5g);
            }

        } catch (\Exception $e) {
            Log::error("Error durante el test de WiFi: " . $e->getMessage());
            $results['error'] = $e->getMessage();
        } finally {
            // 4. Reactivar Ethernet SIEMPRE
            Log::info("Reactivando Ethernet...");
            $networkManager->enableEthernet();
            sleep(2);
        }

        return response()->json([
            'success' => !isset($results['error']),
            'results' => $results
        ]);
    }

    /**
     * Health check simple
     */
    public function health()
    {
        return response()->json([
            'status' => 'online',
            'os' => 'windows',
            'timestamp' => now()->toDateTimeString()
        ]);
    }

    /**
     * Diagnóstico del estado WiFi
     */
    public function wifiDiagnostics()
    {
        $networkManager = NetworkManagerFactory::create();

        try {
            $diagnostics = $networkManager->getWifiDiagnostics();

            return response()->json([
                'success' => true,
                'diagnostics' => $diagnostics,
                'recommendations' => $this->getWifiRecommendations($diagnostics)
            ]);
        } catch (\Exception $e) {
            Log::error("Error en wifiDiagnostics: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Configurar GPON del router
     */
    public function configGpon(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'modelo' => 'required|string',
            'password' => 'required|string',
            'gpon' => 'required|string',
        ]);

        $ip = $request->input('ip');
        $modelo = $request->input('modelo');
        $password = $request->input('password');
        $gpon = $request->input('gpon');
        $username = $request->input('username', 'user');

        Log::info("Configurando GPON {$gpon} para modelo {$modelo} en IP {$ip}");

        try {
            $scrapper = null;
            switch (true) {
                case str_contains($modelo, '2741'):
                    $scrapper = new RouterScrapper2741($ip, $username, $password, $modelo);
                    break;
                case str_contains($modelo, '2541'):
                    $scrapper = new RouterScrapper2541($ip, $username, $password, $modelo);
                    break;
                case str_contains($modelo, '3505'):
                    $scrapper = new RouterScrapper3505($ip, $username, $password, $modelo);
                    break;
                case str_contains($modelo, '8115'):
                    $scrapper = new RouterScrapper8115($ip, $username, $password, $modelo);
                    break;
                default:
                    return response()->json([
                        'success' => false,
                        'message' => "Modelo {$modelo} no soportado para configuración GPON"
                    ], 400);
            }

            // Configurar GPON
            $configResult = $scrapper->configGpon($gpon);

            // Esperar un poco para que el router procese el cambio
            // NO hacemos scrape aquí para no bloquear la sesión del router
            // El frontend hará el scrape después y verificará que se aplicó
            if ($configResult['status'] === 'success') {
                sleep(2);
            }

            return response()->json([
                'success' => $configResult['status'] === 'success',
                'data' => $configResult
            ]);

        } catch (\Exception $e) {
            Log::error("Error en configGpon: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Error al configurar GPON: " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resetear GPON del router a 000000
     */
    public function resetGpon(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'modelo' => 'required|string',
            'password' => 'required|string',
        ]);

        $ip = $request->input('ip');
        $modelo = $request->input('modelo');
        $password = $request->input('password');
        $username = $request->input('username', 'user');

        Log::info("Reseteando GPON para modelo {$modelo} en IP {$ip}");

        try {
            $scrapper = null;
            switch (true) {
                case str_contains($modelo, '2741'):
                    $scrapper = new RouterScrapper2741($ip, $username, $password, $modelo);
                    break;
                case str_contains($modelo, '2541'):
                    $scrapper = new RouterScrapper2541($ip, $username, $password, $modelo);
                    break;
                case str_contains($modelo, '3505'):
                    $scrapper = new RouterScrapper3505($ip, $username, $password, $modelo);
                    break;
                case str_contains($modelo, '8115'):
                    $scrapper = new RouterScrapper8115($ip, $username, $password, $modelo);
                    break;
                default:
                    return response()->json([
                        'success' => false,
                        'message' => "Modelo {$modelo} no soportado para reset GPON"
                    ], 400);
            }

            $resetResult = $scrapper->resetConfigGpon();

            return response()->json([
                'success' => $resetResult['status'] === 'success',
                'data' => $resetResult
            ]);

        } catch (\Exception $e) {
            Log::error("Error en resetGpon: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Error al resetear GPON: " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener recomendaciones basadas en el diagnóstico
     */
    protected function getWifiRecommendations(array $diagnostics): array
    {
        $recommendations = [];

        if ($diagnostics['radio_disabled_by_software'] ?? false) {
            $recommendations[] = [
                'severity' => 'critical',
                'message' => 'El radio WiFi está desactivado por software. Debe habilitarse manualmente o ejecutar el servicio con privilegios de administrador.',
                'action' => 'Ejecutar "netsh wlan set radiostate wifi on" como administrador, o desactivar el Modo Avión en Windows.'
            ];
        }

        if (!($diagnostics['radio_enabled'] ?? true)) {
            $recommendations[] = [
                'severity' => 'warning',
                'message' => 'El radio WiFi no está habilitado.',
                'action' => 'Verificar que el WiFi esté activado en el sistema.'
            ];
        }

        if (empty($recommendations)) {
            $recommendations[] = [
                'severity' => 'info',
                'message' => 'El sistema WiFi parece estar funcionando correctamente.',
                'action' => 'Ninguna acción requerida.'
            ];
        }

        return $recommendations;
    }

    /**
     * Test WiFi con Server-Sent Events para mostrar progreso en tiempo real
     *
     * @param skip_disable_ethernet bool - Si true, no desactiva Ethernet (para test 5GHz cuando ya está desactivado)
     * @param skip_enable_ethernet bool - Si true, no reactiva Ethernet al final (para test 2.4GHz antes de 5GHz)
     */
    public function testWifiSSE(Request $request)
    {
        $request->validate([
            'ssid' => 'required|string',
            'wifi_password' => 'required|string',
            'skip_disable_ethernet' => 'nullable|string',
            'skip_enable_ethernet' => 'nullable|string',
        ]);

        $ssid = $request->input('ssid');
        $password = $request->input('wifi_password');
        $skipDisableEthernet = filter_var($request->input('skip_disable_ethernet', false), FILTER_VALIDATE_BOOLEAN);
        $skipEnableEthernet = filter_var($request->input('skip_enable_ethernet', false), FILTER_VALIDATE_BOOLEAN);

        return response()->stream(function () use ($ssid, $password, $skipDisableEthernet, $skipEnableEthernet) {
            // Desactivar output buffering
            if (ob_get_level()) {
                ob_end_clean();
            }

            $networkManager = NetworkManagerFactory::create();

            $this->sendSSEEvent('status', [
                'phase' => 'init',
                'message' => 'Iniciando test de WiFi...'
            ]);

            try {
                // 1. Desactivar Ethernet (si no está ya desactivado)
                if (!$skipDisableEthernet) {
                    $this->sendSSEEvent('status', [
                        'phase' => 'ethernet',
                        'message' => 'Desactivando Ethernet...'
                    ]);

                    $disableEth = $networkManager->disableEthernet();

                    if (!$disableEth['success']) {
                        $this->sendSSEEvent('error', [
                            'phase' => 'ethernet',
                            'message' => 'No se pudo desactivar Ethernet: ' . $disableEth['output']
                        ]);
                        $this->sendSSEEvent('complete', ['success' => false]);
                        return;
                    }

                    $this->sendSSEEvent('status', [
                        'phase' => 'ethernet',
                        'message' => 'Ethernet desactivado correctamente',
                        'success' => true
                    ]);

                    sleep(2);
                } else {
                    $this->sendSSEEvent('status', [
                        'phase' => 'ethernet',
                        'message' => 'Ethernet ya desactivado (test secuencial)',
                        'skipped' => true
                    ]);
                }

                // 2. Conectar a WiFi
                $this->sendSSEEvent('status', [
                    'phase' => 'wifi_connect',
                    'message' => "Conectando a WiFi: {$ssid}..."
                ]);

                $connectResult = $networkManager->connectToWifi($ssid, $password);

                $wifiConnected = $connectResult['status']['success'] ?? false;

                if (!$wifiConnected) {
                    $this->sendSSEEvent('wifi_result', [
                        'ssid' => $ssid,
                        'connected' => false,
                        'error' => $connectResult['connect']['output'] ?? 'Fallo de conexión'
                    ]);

                    // Limpiar
                    $networkManager->disconnectWifi();
                    $networkManager->removeWifiProfile($ssid);

                    // Reactivar Ethernet solo si no se va a continuar con otro test
                    if (!$skipEnableEthernet) {
                        $networkManager->enableEthernet();
                    }

                    $this->sendSSEEvent('complete', ['success' => false]);
                    return;
                }

                $this->sendSSEEvent('status', [
                    'phase' => 'wifi_connect',
                    'message' => "Conectado a {$ssid}",
                    'success' => true
                ]);

                // 3. Esperar para estabilizar conexión
                $this->sendSSEEvent('status', [
                    'phase' => 'stabilizing',
                    'message' => 'Estabilizando conexión WiFi...'
                ]);

                sleep(3);

                // 4. Probar conectividad a cada sitio
                $sites = [
                    'google' => ['name' => 'Google', 'url' => 'https://www.google.com'],
                    'cloudflare' => ['name' => 'Cloudflare DNS', 'url' => 'https://1.1.1.1'],
                    'youtube' => ['name' => 'YouTube', 'url' => 'https://www.youtube.com'],
                ];

                $siteResults = [];
                $successfulSites = 0;

                foreach ($sites as $key => $site) {
                    $this->sendSSEEvent('testing_site', [
                        'site_key' => $key,
                        'site_name' => $site['name'],
                        'url' => $site['url'],
                        'status' => 'testing'
                    ]);

                    $result = $this->testSingleSite($site['url']);
                    $siteResults[$key] = $result;

                    if ($result['success']) {
                        $successfulSites++;
                    }

                    $this->sendSSEEvent('site_result', [
                        'site_key' => $key,
                        'site_name' => $site['name'],
                        'url' => $site['url'],
                        'success' => $result['success'],
                        'http_code' => $result['http_code'] ?? null,
                        'response_time_ms' => $result['response_time_ms'] ?? null,
                        'error' => $result['error'] ?? null
                    ]);

                    // Pequeña pausa entre sitios
                    usleep(500000); // 0.5 segundos
                }

                // 5. Enviar resultado final del WiFi
                $internetOk = $successfulSites > 0;

                $this->sendSSEEvent('wifi_result', [
                    'ssid' => $ssid,
                    'connected' => true,
                    'internet' => $internetOk,
                    'sites_tested' => count($sites),
                    'sites_successful' => $successfulSites,
                    'details' => $siteResults
                ]);

                // 6. Limpiar conexión WiFi
                $this->sendSSEEvent('status', [
                    'phase' => 'cleanup',
                    'message' => 'Desconectando WiFi...'
                ]);

                $networkManager->disconnectWifi();
                $networkManager->removeWifiProfile($ssid);

                sleep(1);

            } catch (\Exception $e) {
                Log::error("Error en testWifiSSE: " . $e->getMessage());
                $this->sendSSEEvent('error', [
                    'message' => $e->getMessage()
                ]);
            } finally {
                // 7. Reactivar Ethernet (si no se va a continuar con otro test WiFi)
                if (!$skipEnableEthernet) {
                    $this->sendSSEEvent('status', [
                        'phase' => 'ethernet_restore',
                        'message' => 'Reactivando Ethernet...'
                    ]);

                    $networkManager->enableEthernet();

                    sleep(2);

                    $this->sendSSEEvent('status', [
                        'phase' => 'ethernet_restore',
                        'message' => 'Ethernet reactivado',
                        'success' => true
                    ]);
                } else {
                    $this->sendSSEEvent('status', [
                        'phase' => 'ethernet_restore',
                        'message' => 'Ethernet permanece desactivado para siguiente test',
                        'skipped' => true
                    ]);
                }

                $this->sendSSEEvent('complete', [
                    'success' => isset($internetOk) ? $internetOk : false
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * Enviar evento SSE
     */
    protected function sendSSEEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data) . "\n\n";

        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    /**
     * Probar conectividad a un solo sitio
     */
    protected function testSingleSite(string $url): array
    {
        $start = microtime(true);

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_NOBODY => true,
            ]);

            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $end = microtime(true);
            $responseTime = round(($end - $start) * 1000, 2);

            if ($httpCode >= 200 && $httpCode < 400 && empty($error)) {
                return [
                    'success' => true,
                    'http_code' => $httpCode,
                    'response_time_ms' => $responseTime
                ];
            } else {
                return [
                    'success' => false,
                    'http_code' => $httpCode,
                    'error' => $error ?: 'HTTP error',
                    'response_time_ms' => $responseTime
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
