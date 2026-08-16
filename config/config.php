<?php
/**
 * Configuracion global. En produccion mover credenciales a variables de entorno.
 */
return [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'name' => getenv('DB_NAME') ?: 'inventario_sin',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        // mail() nativo de PHP: funciona en hosting compartido (Namecheap)
        // sin dependencias. Para SMTP dedicado, integrar PHPMailer aqui.
        'from' => getenv('MAIL_FROM') ?: 'no-responder@midominio.com',
        'from_nombre' => getenv('MAIL_FROM_NAME') ?: 'SolarStock',
        'reset_ttl_horas' => 24,
    ],
    'app' => [
        'url' => getenv('APP_URL') ?: 'https://midominio.com',
        'session_ttl' => 3600 * 12,
        'storage' => dirname(__DIR__) . '/storage',
    ],
    'siat' => [
        // URLs de los Web Services SOAP del SIN. Completar con las WSDL
        // oficiales entregadas al registrar el sistema (varian por servicio).
        'HOMOLOGACION' => [
            'codigos'        => 'https://pilotosiatservicios.impuestos.gob.bo/v2/FacturacionCodigos?wsdl',
            'sincronizacion' => 'https://pilotosiatservicios.impuestos.gob.bo/v2/FacturacionSincronizacion?wsdl',
            'operaciones'    => 'https://pilotosiatservicios.impuestos.gob.bo/v2/FacturacionOperaciones?wsdl',
            'compraventa'    => 'https://pilotosiatservicios.impuestos.gob.bo/v2/ServicioFacturacionCompraVenta?wsdl',
            'qr_base'        => 'https://pilotosiat.impuestos.gob.bo/consulta/QR?',
        ],
        'PRODUCCION' => [
            'codigos'        => 'https://siatrest.impuestos.gob.bo/v2/FacturacionCodigos?wsdl',
            'sincronizacion' => 'https://siatrest.impuestos.gob.bo/v2/FacturacionSincronizacion?wsdl',
            'operaciones'    => 'https://siatrest.impuestos.gob.bo/v2/FacturacionOperaciones?wsdl',
            'compraventa'    => 'https://siatrest.impuestos.gob.bo/v2/ServicioFacturacionCompraVenta?wsdl',
            'qr_base'        => 'https://siat.impuestos.gob.bo/consulta/QR?',
        ],
        'soap_timeout' => 20,          // segundos por intento
        'soap_reintentos' => 2,        // intentos antes de activar contingencia
        'token_alerta_dias' => 15,     // aviso de vencimiento del token delegado
        'contingencia_limite_horas' => 48,
    ],
];
