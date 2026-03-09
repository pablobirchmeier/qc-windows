<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Servicio global para ejecutar tests de velocidad de internet.
 * Usa speedtest-cli (Ookla) para obtener resultados precisos.
 *
 * Instalación de speedtest-cli:
 * - Windows: winget install Ookla.Speedtest.CLI
 * - O descargar de: https://www.speedtest.net/apps/cli
 */
class SpeedTestService
{
    private static ?SpeedTestService $instance = null;

    /**
     * Obtener instancia singleton
     */
    public static function getInstance(): SpeedTestService
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Ejecutar test de velocidad completo
     *
     * @return array{
     *     status: string,
     *     download: float|null,
     *     upload: float|null,
     *     ping: float|null,
     *     download_formatted: string|null,
     *     upload_formatted: string|null,
     *     server: string|null,
     *     isp: string|null,
     *     timestamp: string,
     *     error: string|null
     * }
     */
    public function runTest(): array
    {
        $result = [
            'status' => 'unknown',
            'download' => null,
            'upload' => null,
            'ping' => null,
            'download_formatted' => null,
            'upload_formatted' => null,
            'server' => null,
            'isp' => null,
            'timestamp' => date('Y-m-d H:i:s'),
            'error' => null
        ];

        try {
            // Intentar con speedtest CLI (Ookla oficial)
            Log::info("SpeedTest: Intentando con speedtest CLI oficial...");
            $output = $this->executeSpeedtestCli();

            if ($output !== null) {
                Log::info("SpeedTest: Output recibido: " . substr($output, 0, 500));
                $parsed = $this->parseSpeedtestOutput($output);

                // Verificar que realmente se parsearon datos
                if (!empty($parsed) && isset($parsed['download']) && $parsed['download'] > 0) {
                    Log::info("SpeedTest: Parse exitoso - Download: {$parsed['download']} Mbps");
                    return array_merge($result, $parsed, ['status' => 'success']);
                }
                Log::warning("SpeedTest: CLI ejecutado pero no se obtuvieron datos válidos");
            }

            // Fallback: intentar con speedtest-cli (Python)
            Log::info("SpeedTest: Intentando con speedtest-cli Python...");
            $output = $this->executeSpeedtestCliPython();

            if ($output !== null) {
                Log::info("SpeedTest: Output Python recibido: " . substr($output, 0, 500));
                $parsed = $this->parseSpeedtestPythonOutput($output);

                if (!empty($parsed) && isset($parsed['download']) && $parsed['download'] > 0) {
                    Log::info("SpeedTest: Parse Python exitoso - Download: {$parsed['download']} Mbps");
                    return array_merge($result, $parsed, ['status' => 'success']);
                }
            }

            // Fallback final: usar test rápido sin CLI
            Log::info("SpeedTest: CLI no disponible, usando test rápido HTTP...");
            return $this->runQuickTest();

        } catch (Exception $e) {
            Log::error("SpeedTest: Error - " . $e->getMessage());
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Test rápido - solo ping y download
     */
    public function runQuickTest(): array
    {
        $result = [
            'status' => 'unknown',
            'download' => null,
            'ping' => null,
            'download_formatted' => null,
            'timestamp' => date('Y-m-d H:i:s'),
            'error' => null
        ];

        try {
            // Usar curl para medir velocidad de descarga básica
            $testUrls = [
                'https://speed.cloudflare.com/__down?bytes=10000000', // 10MB
                'https://proof.ovh.net/files/10Mb.dat',
            ];

            foreach ($testUrls as $url) {
                $speed = $this->measureDownloadSpeed($url);
                if ($speed !== null) {
                    $result['download'] = $speed;
                    $result['download_formatted'] = $this->formatSpeed($speed);
                    $result['status'] = 'success';
                    break;
                }
            }

            // Medir ping
            $ping = $this->measurePing('8.8.8.8');
            if ($ping !== null) {
                $result['ping'] = $ping;
            }

            if ($result['download'] === null) {
                throw new Exception("No se pudo medir la velocidad de descarga");
            }

        } catch (Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Ejecutar speedtest CLI oficial de Ookla
     */
    private function executeSpeedtestCli(): ?string
    {
        $commands = ['speedtest', 'speedtest.exe'];

        foreach ($commands as $cmd) {
            $output = [];
            $returnCode = 0;

            // Ejecutar con formato JSON
            exec("$cmd --format=json --accept-license --accept-gdpr 2>&1", $output, $returnCode);

            if ($returnCode === 0 && !empty($output)) {
                return implode("\n", $output);
            }
        }

        return null;
    }

    /**
     * Ejecutar speedtest-cli de Python
     */
    private function executeSpeedtestCliPython(): ?string
    {
        $output = [];
        $returnCode = 0;

        exec("speedtest-cli --json 2>&1", $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            return implode("\n", $output);
        }

        return null;
    }

    /**
     * Parsear salida JSON de speedtest CLI oficial
     */
    private function parseSpeedtestOutput(string $output): array
    {
        $json = json_decode($output, true);

        if (!$json) {
            return [];
        }

        // Ookla speedtest CLI devuelve velocidad en bytes/s
        $downloadBps = $json['download']['bandwidth'] ?? 0;
        $uploadBps = $json['upload']['bandwidth'] ?? 0;

        // Convertir de bytes/s a Mbps
        $downloadMbps = ($downloadBps * 8) / 1000000;
        $uploadMbps = ($uploadBps * 8) / 1000000;

        return [
            'download' => round($downloadMbps, 2),
            'upload' => round($uploadMbps, 2),
            'ping' => round($json['ping']['latency'] ?? 0, 2),
            'download_formatted' => $this->formatSpeed($downloadMbps),
            'upload_formatted' => $this->formatSpeed($uploadMbps),
            'server' => $json['server']['name'] ?? null,
            'isp' => $json['isp'] ?? null,
        ];
    }

    /**
     * Parsear salida JSON de speedtest-cli Python
     */
    private function parseSpeedtestPythonOutput(string $output): array
    {
        $json = json_decode($output, true);

        if (!$json) {
            return [];
        }

        // speedtest-cli Python devuelve en bits/s
        $downloadMbps = ($json['download'] ?? 0) / 1000000;
        $uploadMbps = ($json['upload'] ?? 0) / 1000000;

        return [
            'download' => round($downloadMbps, 2),
            'upload' => round($uploadMbps, 2),
            'ping' => round($json['ping'] ?? 0, 2),
            'download_formatted' => $this->formatSpeed($downloadMbps),
            'upload_formatted' => $this->formatSpeed($uploadMbps),
            'server' => $json['server']['sponsor'] ?? null,
            'isp' => $json['client']['isp'] ?? null,
        ];
    }

    /**
     * Medir velocidad de descarga con curl/file_get_contents
     */
    private function measureDownloadSpeed(string $url): ?float
    {
        $startTime = microtime(true);

        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            return null;
        }

        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        $bytes = strlen($content);

        // Calcular Mbps
        $bitsPerSecond = ($bytes * 8) / $duration;
        $mbps = $bitsPerSecond / 1000000;

        return round($mbps, 2);
    }

    /**
     * Medir ping a un host
     */
    private function measurePing(string $host): ?float
    {
        $output = [];
        $returnCode = 0;

        // Windows ping
        exec("ping -n 1 $host 2>&1", $output, $returnCode);

        if ($returnCode === 0) {
            $outputStr = implode("\n", $output);

            // Buscar tiempo en español o inglés
            if (preg_match('/tiempo[=<](\d+)ms/i', $outputStr, $matches)) {
                return (float) $matches[1];
            }
            if (preg_match('/time[=<](\d+)ms/i', $outputStr, $matches)) {
                return (float) $matches[1];
            }
        }

        return null;
    }

    /**
     * Formatear velocidad para mostrar
     */
    private function formatSpeed(float $mbps): string
    {
        if ($mbps >= 1000) {
            return round($mbps / 1000, 2) . ' Gbps';
        }
        return round($mbps, 2) . ' Mbps';
    }

    /**
     * Verificar si speedtest-cli está instalado
     */
    public function isSpeedtestInstalled(): bool
    {
        $output = [];
        $returnCode = 0;

        exec("speedtest --version 2>&1", $output, $returnCode);

        if ($returnCode === 0) {
            return true;
        }

        exec("speedtest-cli --version 2>&1", $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Obtener instrucciones de instalación
     */
    public function getInstallInstructions(): string
    {
        return "Para instalar speedtest-cli:\n" .
               "Windows: winget install Ookla.Speedtest.CLI\n" .
               "O descargar de: https://www.speedtest.net/apps/cli\n" .
               "Python: pip install speedtest-cli";
    }
}
