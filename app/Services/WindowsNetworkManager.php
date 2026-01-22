<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class WindowsNetworkManager implements NetworkManagerInterface
{
    protected string $ethernetInterface = 'Ethernet';
    protected string $wifiInterface = 'Wi-Fi';

    public function setEthernetInterface(string $name): self
    {
        $this->ethernetInterface = $name;
        return $this;
    }

    public function setWifiInterface(string $name): self
    {
        $this->wifiInterface = $name;
        return $this;
    }

    /**
     * Desactivar interfaz Ethernet
     */
    public function disableEthernet(): array
    {
        $adapterName = $this->detectEthernetAdapter();
        if ($adapterName) {
            $this->ethernetInterface = $adapterName;
        }

        Log::info("Intentando desactivar adaptador: {$this->ethernetInterface}");

        $command1 = "powershell -Command \"Disable-NetAdapter -Name '{$this->ethernetInterface}' -Confirm:\$false\" 2>&1";
        $result1 = $this->executeCommand($command1, 'Desactivar Ethernet (PowerShell)');

        if ($result1['return_code'] !== 0) {
            Log::warning("PowerShell falló, intentando con netsh...");
            $command2 = "netsh interface set interface \"{$this->ethernetInterface}\" admin=disable";
            $result2 = $this->executeCommand($command2, 'Desactivar Ethernet (netsh)');

            if ($result2['return_code'] !== 0) {
                Log::warning("netsh falló, intentando con WMI...");
                $command3 = "powershell -Command \"Get-WmiObject -Class Win32_NetworkAdapter | Where-Object { \$_.NetConnectionID -eq '{$this->ethernetInterface}' } | ForEach-Object { \$_.Disable() }\" 2>&1";
                return $this->executeCommand($command3, 'Desactivar Ethernet (WMI)');
            }
            return $result2;
        }
        return $result1;
    }

    /**
     * Activar interfaz Ethernet
     */
    public function enableEthernet(): array
    {
        Log::info("Intentando activar adaptador: {$this->ethernetInterface}");

        $command1 = "powershell -Command \"Enable-NetAdapter -Name '{$this->ethernetInterface}' -Confirm:\$false\" 2>&1";
        $result1 = $this->executeCommand($command1, 'Activar Ethernet (PowerShell)');

        if ($result1['return_code'] !== 0) {
            $command2 = "netsh interface set interface \"{$this->ethernetInterface}\" admin=enable";
            $result2 = $this->executeCommand($command2, 'Activar Ethernet (netsh)');

            if ($result2['return_code'] !== 0) {
                $command3 = "powershell -Command \"Get-WmiObject -Class Win32_NetworkAdapter | Where-Object { \$_.NetConnectionID -eq '{$this->ethernetInterface}' } | ForEach-Object { \$_.Enable() }\" 2>&1";
                return $this->executeCommand($command3, 'Activar Ethernet (WMI)');
            }
            return $result2;
        }

        return $result1;
    }

    protected function detectEthernetAdapter(): ?string
    {
        $command = 'powershell -Command "Get-NetAdapter | Where-Object { $_.Status -eq \'Up\' -and $_.PhysicalMediaType -eq \'802.3\' } | Select-Object -First 1 -ExpandProperty Name"';
        $result = $this->executeCommand($command, 'Detectar adaptador Ethernet');

        $adapterName = trim($result['output']);

        if (!empty($adapterName) && $result['return_code'] === 0) {
            Log::info("Adaptador Ethernet detectado: {$adapterName}");
            return $adapterName;
        }

        $command2 = 'powershell -Command "Get-NetAdapter | Where-Object { $_.Name -like \'*Ethernet*\' -or $_.Name -like \'*LAN*\' } | Select-Object -First 1 -ExpandProperty Name"';
        $result2 = $this->executeCommand($command2, 'Detectar adaptador Ethernet (fallback)');

        $adapterName2 = trim($result2['output']);
        if (!empty($adapterName2)) {
            Log::info("Adaptador Ethernet detectado (fallback): {$adapterName2}");
            return $adapterName2;
        }

        Log::warning("No se pudo detectar adaptador Ethernet, usando default: {$this->ethernetInterface}");
        return null;
    }

    public function connectToWifi(string $ssid, string $password): array
    {
        $profileXml = $this->generateWifiProfileXml($ssid, $password);
        $profilePath = storage_path("app/wifi_profile_" . preg_replace('/[^a-zA-Z0-9]/', '_', $ssid) . ".xml");

        file_put_contents($profilePath, $profileXml);

        $results = [];
        $addProfileCmd = "netsh wlan add profile filename=\"{$profilePath}\"";
        $results['add_profile'] = $this->executeCommand($addProfileCmd, "Agregar perfil WiFi {$ssid}");

        $connectCmd = "netsh wlan connect name=\"{$ssid}\" ssid=\"{$ssid}\" interface=\"{$this->wifiInterface}\"";
        $results['connect'] = $this->executeCommand($connectCmd, "Conectar a {$ssid}");

        sleep(5);
        $results['status'] = $this->getWifiStatus();

        if (file_exists($profilePath)) {
            unlink($profilePath);
        }

        return $results;
    }

    public function disconnectWifi(): array
    {
        $command = "netsh wlan disconnect interface=\"{$this->wifiInterface}\"";
        return $this->executeCommand($command, 'Desconectar WiFi');
    }

    public function removeWifiProfile(string $ssid): array
    {
        $command = "netsh wlan delete profile name=\"{$ssid}\"";
        return $this->executeCommand($command, "Eliminar perfil {$ssid}");
    }

    public function getWifiStatus(): array
    {
        $command = "netsh wlan show interfaces";
        return $this->executeCommand($command, 'Estado WiFi');
    }

    public function getNetworkInterfaces(): array
    {
        $command = "powershell -Command \"Get-NetAdapter | Select-Object Name, Status, InterfaceDescription | ConvertTo-Json\"";
        return $this->executeCommand($command, 'Listar interfaces');
    }

    public function testInternetConnectivity(): array
    {
        $sites = [
            'google' => 'https://www.google.com',
            'cloudflare' => 'https://1.1.1.1',
        ];

        $results = [];
        $successful = 0;
        $failed = 0;

        foreach ($sites as $name => $url) {
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
                    $results[$name] = [
                        'status' => 'success',
                        'http_code' => $httpCode,
                        'response_time_ms' => $responseTime
                    ];
                    $successful++;
                } else {
                    $results[$name] = [
                        'status' => 'failed',
                        'http_code' => $httpCode,
                        'error' => $error ?: 'HTTP error',
                        'response_time_ms' => $responseTime
                    ];
                    $failed++;
                }
            } catch (\Exception $e) {
                $results[$name] = [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
                $failed++;
            }
        }

        return [
            'internet_connection' => $successful > 0,
            'summary' => [
                'total' => count($sites),
                'successful' => $successful,
                'failed' => $failed,
            ],
            'details' => $results
        ];
    }

    protected function generateWifiProfileXml(string $ssid, string $password): string
    {
        $ssidHex = bin2hex($ssid);
        return <<<XML
<?xml version="1.0"?>
<WLANProfile xmlns="http://www.microsoft.com/networking/WLAN/profile/v1">
    <name>{$ssid}</name>
    <SSIDConfig>
        <SSID>
            <hex>{$ssidHex}</hex>
            <name>{$ssid}</name>
        </SSID>
    </SSIDConfig>
    <connectionType>ESS</connectionType>
    <connectionMode>auto</connectionMode>
    <MSM>
        <security>
            <authEncryption>
                <authentication>WPA2PSK</authentication>
                <encryption>AES</encryption>
                <useOneX>false</useOneX>
            </authEncryption>
            <sharedKey>
                <keyType>passPhrase</keyType>
                <protected>false</protected>
                <keyMaterial>{$password}</keyMaterial>
            </sharedKey>
        </security>
    </MSM>
</WLANProfile>
XML;
    }

    protected function executeCommand(string $command, string $description): array
    {
        Log::info("Ejecutando: {$description} - {$command}");
        $output = [];
        $returnCode = 0;
        exec($command . " 2>&1", $output, $returnCode);

        $outputString = implode("\n", $output);
        $outputString = $this->sanitizeUtf8($outputString);

        return [
            'description' => $description,
            'command' => $command,
            'output' => $outputString,
            'return_code' => $returnCode,
            'success' => $returnCode === 0
        ];
    }

    protected function sanitizeUtf8(string $string): string
    {
        $converted = @iconv('CP1252', 'UTF-8//IGNORE', $string);
        if ($converted !== false) { $string = $converted; }
        $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
        $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string);
        return $string;
    }
}
