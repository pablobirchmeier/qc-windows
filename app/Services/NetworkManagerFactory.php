<?php

namespace App\Services;

class NetworkManagerFactory
{
    public static function create(): NetworkManagerInterface
    {
        return new WindowsNetworkManager();
    }

    public static function getDetectedOS(): string
    {
        return 'windows';
    }
}
