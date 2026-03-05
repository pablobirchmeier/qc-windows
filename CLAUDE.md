# QC-Windows - Módulo de Testeo Automatizado de Routers (Windows)

## Descripción

Este módulo es parte del sistema Quantum Check y se ejecuta en máquinas Windows para realizar testing automatizado de routers. Se comunica con el frontend `TesteoAutomatizado.vue` ubicado en `www/remozado/front-remozado-cl/src/views/estaciones/Testeo/`.

## Stack Tecnológico

- **Backend:** Laravel 11 (PHP 8.x)
- **OS:** Windows 10/11
- **Puerto por defecto:** 8001 (`php artisan serve --port=8001`)

## Arquitectura

```
┌─────────────────────────────────────────────────────────────────┐
│              FRONT-END (front-remozado-cl)                       │
│          TesteoAutomatizado.vue                                  │
│              http://localhost:5173                               │
└───────────────────────────┬─────────────────────────────────────┘
                            │ HTTP / SSE
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│              QC-WINDOWS (este proyecto)                          │
│              Laravel API en puerto 8001                          │
│                                                                  │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  DeviceTestController                                    │    │
│  │  - scrapeData()      → Extrae datos del router          │    │
│  │  - testEthernet()    → Prueba internet por cable        │    │
│  │  - testWifi()        → Prueba WiFi 2.4GHz y 5GHz        │    │
│  │  - testWifiSSE()     → Prueba WiFi con eventos SSE      │    │
│  │  - wifiDiagnostics() → Diagnóstico del estado WiFi      │    │
│  └─────────────────────────────────────────────────────────┘    │
│                            │                                     │
│                            ▼                                     │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  WindowsNetworkManager                                   │    │
│  │  - disableEthernet() / enableEthernet()                 │    │
│  │  - connectToWifi() / disconnectWifi()                   │    │
│  │  - testInternetConnectivity()                           │    │
│  │  - getWifiDiagnostics()                                 │    │
│  └─────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                     ROUTER (192.168.1.1)                         │
│              Modelos soportados: HG8115, HG3505                  │
└─────────────────────────────────────────────────────────────────┘
```

## Flujo de Testeo Automatizado

1. **Extracción de datos (Scraping)**
   - Frontend envía POST a `/api/device/scrape`
   - Se extraen: SSID, password WiFi (2.4GHz y 5GHz), GPON, etc.

2. **Test de puertos LAN (Ethernet)**
   - POST a `/api/device/test-ethernet`
   - Prueba conectividad a Google, Cloudflare, YouTube

3. **Test WiFi 2.4GHz**
   - GET a `/api/device/test-wifi-sse` (Server-Sent Events)
   - Desactiva Ethernet → Conecta a WiFi → Prueba internet → Restaura Ethernet

4. **Test WiFi 5GHz**
   - Mismo proceso que 2.4GHz pero con el SSID de 5GHz

## Archivos Clave

| Archivo | Descripción |
|---------|-------------|
| `app/Http/Controllers/Api/DeviceTestController.php` | Controlador principal de la API |
| `app/Services/WindowsNetworkManager.php` | Manejo de interfaces de red en Windows |
| `app/Services/RouterScrapper8115.php` | Scraper para routers Huawei HG8115 |
| `app/Services/RouterScrapper3505.php` | Scraper para routers Huawei HG3505 |
| `routes/api.php` | Definición de rutas API |

## API Endpoints

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/device/health` | Health check del servicio |
| POST | `/api/device/scrape` | Extraer datos del router |
| POST | `/api/device/test-ethernet` | Test de internet por Ethernet |
| POST | `/api/device/test-wifi` | Test de WiFi (síncrono) |
| GET | `/api/device/test-wifi-sse` | Test de WiFi con eventos en tiempo real |
| GET | `/api/device/wifi-diagnostics` | Diagnóstico del estado WiFi |
| POST | `/api/device/config-gpon` | Configurar GPON/SLID del router |
| POST | `/api/device/reset-gpon` | Resetear GPON a 000000 |

### Configuración GPON

El endpoint `/api/device/config-gpon` permite configurar el identificador GPON (SLID) del router antes de conectar la fibra.

**Request:**
```json
{
  "ip": "192.168.1.1",
  "modelo": "3505",
  "password": "password_del_router",
  "gpon": "5483613"
}
```

**Fibras disponibles (configuradas en el frontend):**
- Fibra 1: `5483613`
- Fibra 2: `5985048`
- Fibra 3: `5985057`

## Problemas Conocidos y Soluciones

### 1. WiFi no se conecta (Radio desactivado por software)

**Síntoma:** El test de WiFi falla, el Ethernet se desactiva pero no se conecta al WiFi.

**Diagnóstico:**
```bash
netsh wlan show interfaces
# Si muestra "Estado de radio: Software Desactivado"
```

**Solución:**
- Ejecutar Laravel con privilegios de administrador
- O manualmente: `netsh wlan set radiostate wifi on` (como admin)
- O desactivar el Modo Avión en Windows

### 2. Idioma del sistema

El sistema soporta tanto español como inglés en las verificaciones de estado WiFi:
- Español: "estado: conectado/desconectado"
- Inglés: "state: connected/disconnected"

### 3. Nombres de adaptadores

El código auto-detecta los nombres de los adaptadores de red, pero por defecto usa:
- Ethernet: `Ethernet`
- WiFi: `Wi-Fi`

Si los nombres son diferentes, verificar con:
```powershell
Get-NetAdapter | Select Name, Status
```

## Configuración Requerida

### Permisos de Administrador

Para que el manejo de redes funcione correctamente, el servicio Laravel debe ejecutarse con privilegios de administrador. Esto es necesario para:
- Habilitar/deshabilitar adaptadores de red
- Habilitar el radio WiFi
- Crear/eliminar perfiles de red WiFi

### Variables de Entorno (.env)

```env
APP_URL=http://localhost:8001
```

## Comandos Útiles

```bash
# Iniciar el servidor
php artisan serve --port=8001

# Ver logs en tiempo real
tail -f storage/logs/laravel.log

# Verificar estado WiFi
netsh wlan show interfaces

# Ver redes disponibles
netsh wlan show networks

# Habilitar radio WiFi (requiere admin)
netsh wlan set radiostate wifi on
```

## Frontend Relacionado

El frontend se encuentra en:
```
c:\laragon\www\remozado\front-remozado-cl\src\views\estaciones\Testeo\TesteoAutomatizado.vue
```

Usa Server-Sent Events (SSE) para recibir actualizaciones en tiempo real durante el test WiFi.

## Notas de Desarrollo

1. Los perfiles WiFi se crean temporalmente como XML en `storage/app/` y se eliminan después del test
2. El sistema usa WPA2-PSK/AES por defecto para los perfiles WiFi
3. La verificación de conexión WiFi tiene reintentos (3 intentos, 5 segundos cada uno)
4. Siempre se restaura el Ethernet al finalizar el test WiFi (en el bloque `finally`)
5. La configuración GPON debe hacerse con la fibra DESCONECTADA
6. Ambos scrappers (8115 y 3505) soportan `configGpon()` y `resetConfigGpon()`
7. El 3505 convierte el GPON a hexadecimal, el 8115 lo envía como ASCII
