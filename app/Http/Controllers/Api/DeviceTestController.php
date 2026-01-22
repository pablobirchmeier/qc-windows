<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NetworkManagerFactory;
use App\Services\RouterScrapper3505;
use App\Services\RouterScrapper8115;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class DeviceTestController extends Controller
{
    /**
     * Extraer datos del equipo (Scraping)
     */
    public function scrapeData(Request $request)
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

        Log::info("Iniciando scrap para modelo {$modelo} en IP {$ip}");

        try {
            $scrapper = null;
            if (str_contains($modelo, '3505')) {
                $scrapper = new RouterScrapper3505($ip, $username, $password, $modelo);
            } elseif (str_contains($modelo, '8115')) {
                $scrapper = new RouterScrapper8115($ip, $username, $password, $modelo);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => "Modelo {$modelo} no soportado para scraping"
                ], 400);
            }

            $result = $scrapper->scrape();

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
}
