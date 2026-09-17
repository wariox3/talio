<?php

namespace App\Utilidades;

class BdLogNginx
{
    private const ZONA_HORARIA = 'America/Bogota';

    private ?\PDO $conexion = null;

    private function conexion(): \PDO
    {
        if ($this->conexion === null) {
            $url = parse_url($_ENV['DATABASE_BDLOGNGINX_URL']);
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s',
                $url['host'], $url['port'] ?? 5432, ltrim($url['path'], '/'));
            $this->conexion = new \PDO($dsn, urldecode($url['user']), urldecode($url['pass'] ?? ''), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
        }
        return $this->conexion;
    }

    /**
     * Accesos de las últimas 24 horas agrupados por hora, incluidas las horas sin accesos.
     */
    public function accesosPorHora(): array
    {
        $sql = "SELECT to_char(h.hora, 'HH24') AS hora, count(a.id) AS cantidad
                FROM generate_series(
                    date_trunc('hour', now() AT TIME ZONE :zona) - interval '23 hours',
                    date_trunc('hour', now() AT TIME ZONE :zona),
                    interval '1 hour') AS h(hora)
                LEFT JOIN nginx_acceso a
                    ON a.fecha >= h.hora AT TIME ZONE :zona
                    AND a.fecha < (h.hora + interval '1 hour') AT TIME ZONE :zona
                GROUP BY h.hora
                ORDER BY h.hora";
        try {
            $consulta = $this->conexion()->prepare($sql);
            $consulta->execute(['zona' => self::ZONA_HORARIA]);
            return [
                'error' => false,
                'datos' => $consulta->fetchAll()
            ];
        } catch (\PDOException $e) {
            return [
                'error' => true,
                'mensaje' => $e->getMessage()
            ];
        }
    }

    /**
     * Últimos accesos registrados, del más reciente al más antiguo.
     */
    public function ultimosAccesos(int $limite = 20): array
    {
        $sql = "SELECT id, to_char(fecha AT TIME ZONE :zona, 'YYYY-MM-DD HH24:MI:SS') AS fecha,
                    host, host(ip) AS ip, metodo, uri, status, bytes, request_time
                FROM nginx_acceso
                ORDER BY fecha DESC
                LIMIT :limite";
        try {
            $consulta = $this->conexion()->prepare($sql);
            $consulta->bindValue('zona', self::ZONA_HORARIA);
            $consulta->bindValue('limite', $limite, \PDO::PARAM_INT);
            $consulta->execute();
            return [
                'error' => false,
                'datos' => $consulta->fetchAll()
            ];
        } catch (\PDOException $e) {
            return [
                'error' => true,
                'mensaje' => $e->getMessage()
            ];
        }
    }

    /**
     * Total de accesos por host en las últimas 24 horas, del que más tiene al que menos.
     * Usa la misma ventana que accesosPorHora() para que los totales coincidan con la gráfica.
     */
    public function accesosPorHost(): array
    {
        $sql = "SELECT coalesce(host, '(sin host)') AS host, count(*) AS total
                FROM nginx_acceso
                WHERE fecha >= (date_trunc('hour', now() AT TIME ZONE :zona) - interval '23 hours') AT TIME ZONE :zona
                GROUP BY host
                ORDER BY total DESC, host";
        try {
            $consulta = $this->conexion()->prepare($sql);
            $consulta->execute(['zona' => self::ZONA_HORARIA]);
            return [
                'error' => false,
                'datos' => $consulta->fetchAll()
            ];
        } catch (\PDOException $e) {
            return [
                'error' => true,
                'mensaje' => $e->getMessage()
            ];
        }
    }

    /**
     * URIs con más accesos en las últimas 24 horas. Agrupa por ruta, sin la query string,
     * para que /lista?page=1 y /lista?page=2 cuenten como la misma URI.
     */
    public function accesosPorUri(int $limite = 20): array
    {
        $sql = "SELECT coalesce(host, '(sin host)') AS host, split_part(uri, '?', 1) AS uri, count(*) AS total
                FROM nginx_acceso
                WHERE fecha >= (date_trunc('hour', now() AT TIME ZONE :zona) - interval '23 hours') AT TIME ZONE :zona
                GROUP BY host, split_part(uri, '?', 1)
                ORDER BY total DESC, uri
                LIMIT :limite";
        try {
            $consulta = $this->conexion()->prepare($sql);
            $consulta->bindValue('zona', self::ZONA_HORARIA);
            $consulta->bindValue('limite', $limite, \PDO::PARAM_INT);
            $consulta->execute();
            return [
                'error' => false,
                'datos' => $consulta->fetchAll()
            ];
        } catch (\PDOException $e) {
            return [
                'error' => true,
                'mensaje' => $e->getMessage()
            ];
        }
    }
}
