<?php

namespace App\Services;

interface NetworkManagerInterface
{
    public function setEthernetInterface(string $name): self;
    public function setWifiInterface(string $name): self;
    public function disableEthernet(): array;
    public function enableEthernet(): array;
    public function connectToWifi(string $ssid, string $password): array;
    public function disconnectWifi(): array;
    public function removeWifiProfile(string $ssid): array;
    public function getWifiStatus(): array;
    public function getNetworkInterfaces(): array;
    public function testInternetConnectivity(): array;
}
