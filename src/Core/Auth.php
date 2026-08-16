<?php
namespace Core;

class Auth
{
    /** Sesion de usuario (frontend movil/web) via Bearer token */
    public static function user(): ?array
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s+([a-f0-9]{64})/i', $h, $m)) return null;
        $row = DB::q(
            'SELECT u.* FROM sesion s JOIN usuario u ON u.id = s.usuario_id
             WHERE s.token = ? AND s.expira > NOW() AND u.activo = 1', [$m[1]]
        )->fetch();
        return $row ?: null;
    }

    /** Autenticacion de sistemas externos (ERP/CRM/POS) via X-Api-Key */
    public static function apiKey(?string $permisoRequerido = null): ?array
    {
        $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if ($key === '') return null;
        $row = DB::q('SELECT * FROM api_key WHERE llave = ? AND activa = 1', [$key])->fetch();
        if (!$row) return null;
        if ($permisoRequerido !== null) {
            $permisos = json_decode($row['permisos'], true) ?: [];
            if (!in_array($permisoRequerido, $permisos, true) && !in_array('*', $permisos, true)) {
                return null;
            }
        }
        DB::q('UPDATE api_key SET ultimo_uso = NOW() WHERE id = ?', [$row['id']]);
        return $row;
    }

    public static function requireUser(array $roles = []): array
    {
        $u = self::user();
        if (!$u) Response::error('No autenticado', 401);
        if ($roles && !in_array($u['rol'], $roles, true)) Response::error('Sin permisos', 403);
        return $u;
    }

    public static function login(string $email, string $pass): ?array
    {
        $u = DB::q('SELECT * FROM usuario WHERE email = ? AND activo = 1', [$email])->fetch();
        if (!$u || !password_verify($pass, $u['pass_hash'])) return null;
        $token = bin2hex(random_bytes(32));
        $ttl = (require dirname(__DIR__, 2) . '/config/config.php')['app']['session_ttl'];
        DB::q('INSERT INTO sesion (token, usuario_id, expira) VALUES (?,?, DATE_ADD(NOW(), INTERVAL ? SECOND))',
              [$token, $u['id'], $ttl]);
        DB::q('UPDATE usuario SET ultimo_login = NOW() WHERE id = ?', [$u['id']]);
        unset($u['pass_hash']);
        return ['token' => $token, 'usuario' => $u];
    }
}

/**
 * Permisos granulares: el rol define los permisos por defecto y la tabla
 * usuario_permiso agrega (concedido=1) o revoca (concedido=0) por usuario.
 */
class Permisos
{
    public const POR_ROL = [
        'admin'   => ['*'],
        'gerente' => ['dashboard.ver','productos.ver','productos.crear','productos.importar',
                      'inventario.ver','inventario.ajustar','compras.ver','compras.crear',
                      'ventas.crear','ventas.anular','facturas.emitir','facturas.anular',
                      'facturas.leer','conciliacion.ver','conciliacion.ejecutar','api.gestionar','siat.gestionar',
                      'informes.ver','informes.exportar','almacenes.gestionar','pagos.ver','pagos.gestionar'],
        'cajero'  => ['dashboard.ver','productos.ver','ventas.crear','facturas.emitir','facturas.leer'],
        'almacen' => ['productos.ver','productos.crear','inventario.ver','inventario.ajustar',
                      'compras.ver','compras.crear','almacenes.gestionar'],
        'auditor' => ['dashboard.ver','productos.ver','inventario.ver','compras.ver',
                      'conciliacion.ver','facturas.leer','informes.ver','informes.exportar','almacenes.gestionar','pagos.ver','pagos.gestionar'],
    ];

    public static function de(array $usuario): array
    {
        $base = self::POR_ROL[$usuario['rol']] ?? [];
        if (in_array('*', $base, true)) {
            $base = array_column(DB::q('SELECT clave FROM permiso')->fetchAll(), 'clave');
        }
        $overrides = DB::q('SELECT permiso_clave, concedido FROM usuario_permiso WHERE usuario_id = ?',
                           [$usuario['id']])->fetchAll();
        foreach ($overrides as $o) {
            if ($o['concedido']) { $base[] = $o['permiso_clave']; }
            else { $base = array_values(array_diff($base, [$o['permiso_clave']])); }
        }
        return array_values(array_unique($base));
    }

    public static function puede(array $usuario, string $permiso): bool
    {
        return in_array($permiso, self::de($usuario), true);
    }

    public static function exigir(array $usuario, string $permiso): void
    {
        if (!self::puede($usuario, $permiso)) {
            Response::error("Permiso requerido: $permiso", 403);
        }
    }
}
