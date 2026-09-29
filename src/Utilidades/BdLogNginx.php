<?php

namespace App\Utilidades;

class BdLogNginx
{
    private const ZONA_HORARIA = 'America/Bogota';

    /**
     * Periodos que se pueden consultar, en horas. El paso es el tamaño de cada punto de la
     * gráfica: por hora en los periodos largos y en minutos en los cortos, para que la
     * gráfica tenga suficientes puntos.
     */
    public const PERIODOS = [
        24 => ['paso' => 60, 'texto' => 'Últimas 24 h'],
        12 => ['paso' => 60, 'texto' => 'Últimas 12 h'],
        6 => ['paso' => 15, 'texto' => 'Últimas 6 h'],
        1 => ['paso' => 5, 'texto' => 'Última hora'],
    ];
    public const PERIODO_DEFECTO = 24;

    /**
     * Grupos de status que se pueden filtrar además de un código exacto, con su rango.
     */
    public const GRUPOS_STATUS = [
        'errores' => ['min' => 400, 'max' => 599, 'texto' => 'Errores (4xx y 5xx)'],
        '2xx' => ['min' => 200, 'max' => 299, 'texto' => '2xx Correctos'],
        '3xx' => ['min' => 300, 'max' => 399, 'texto' => '3xx Redirecciones'],
        '4xx' => ['min' => 400, 'max' => 499, 'texto' => '4xx Errores del cliente'],
        '5xx' => ['min' => 500, 'max' => 599, 'texto' => '5xx Errores del servidor'],
    ];

    /**
     * Inicio del periodo, en hora local: el inicio del intervalo actual menos los intervalos
     * restantes. Todas las consultas lo usan para que los totales coincidan con la gráfica.
     */
    private const INICIO_PERIODO = "(date_bin(CAST(:paso AS interval), now() AT TIME ZONE :zona, timestamp '2000-01-01')
                    - CAST(:paso AS interval) * (CAST(:puntos AS integer) - 1))";

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
     * Parámetros de la ventana de tiempo para las consultas. Un periodo no válido
     * se trata como el de defecto.
     */
    private function ventana(int $horas): array
    {
        if (!isset(self::PERIODOS[$horas])) {
            $horas = self::PERIODO_DEFECTO;
        }
        $paso = self::PERIODOS[$horas]['paso'];
        return [
            'zona' => self::ZONA_HORARIA,
            'paso' => $paso . ' minutes',
            'puntos' => intdiv($horas * 60, $paso),
        ];
    }

    /**
     * Condición SQL de los filtros opcionales: cada uno con su parámetro en NULL no filtra.
     * El filtro de IP es por ip_real (la IP del cliente), no por ip (la de la conexión, que detrás
     * de Cloudflare es un nodo compartido por muchos clientes).
     * El alias es el de la tabla nginx_acceso cuando la consulta tiene joins.
     */
    private function condicionFiltros(string $alias = ''): string
    {
        $c = $alias === '' ? '' : $alias . '.';
        return "(CAST(:ip AS inet) IS NULL OR {$c}ip_real = CAST(:ip AS inet))
                    AND (CAST(:api_key AS text) IS NULL OR {$c}api_key = CAST(:api_key AS text))
                    AND (CAST(:ruta AS text) IS NULL OR {$c}ruta = CAST(:ruta AS text))
                    AND (CAST(:host AS text) IS NULL OR {$c}host = CAST(:host AS text))
                    AND (CAST(:status_min AS integer) IS NULL
                        OR {$c}status BETWEEN CAST(:status_min AS integer) AND CAST(:status_max AS integer))";
    }

    /**
     * Parámetros de los filtros opcionales; los que no vienen quedan en NULL.
     * El status es un grupo de GRUPOS_STATUS o un código exacto, y se consulta como rango.
     *
     * @param array{ip?: ?string, api_key?: ?string, ruta?: ?string, host?: ?string, status?: ?string} $filtros
     */
    private function parametrosFiltros(array $filtros): array
    {
        return [
            'ip' => $filtros['ip'] ?? null,
            'api_key' => $filtros['api_key'] ?? null,
            'ruta' => $filtros['ruta'] ?? null,
            'host' => $filtros['host'] ?? null,
            ...$this->rangoStatus($filtros['status'] ?? null),
        ];
    }

    /**
     * Rango de un filtro de status: un grupo de GRUPOS_STATUS o un código exacto.
     * Lo que no es ninguno de los dos no filtra.
     */
    private function rangoStatus(?string $status): array
    {
        if ($status !== null && isset(self::GRUPOS_STATUS[$status])) {
            return ['status_min' => self::GRUPOS_STATUS[$status]['min'], 'status_max' => self::GRUPOS_STATUS[$status]['max']];
        }
        if (self::esCodigoStatus($status)) {
            return ['status_min' => (int)$status, 'status_max' => (int)$status];
        }
        return ['status_min' => null, 'status_max' => null];
    }

    public static function esCodigoStatus(?string $status): bool
    {
        return $status !== null && preg_match('/^[1-5]\d\d$/', $status) === 1;
    }

    private function enlazarFiltros(\PDOStatement $consulta, array $filtros): void
    {
        foreach ($this->parametrosFiltros($filtros) as $clave => $valor) {
            $consulta->bindValue($clave, $valor, $valor === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        }
    }

    /**
     * Servidores que tienen accesos registrados, en orden alfabético.
     */
    public function servidores(): array
    {
        $sql = "SELECT DISTINCT servidor FROM nginx_acceso WHERE servidor IS NOT NULL ORDER BY servidor";
        try {
            return [
                'error' => false,
                'datos' => $this->conexion()->query($sql)->fetchAll(\PDO::FETCH_COLUMN)
            ];
        } catch (\PDOException $e) {
            return [
                'error' => true,
                'mensaje' => $e->getMessage()
            ];
        }
    }

    /**
     * API keys que tienen accesos en el periodo, en orden alfabético.
     */
    public function apiKeys(string $servidor, int $horas = self::PERIODO_DEFECTO): array
    {
        $sql = "SELECT DISTINCT api_key
                FROM nginx_acceso
                WHERE servidor = :servidor
                    AND api_key IS NOT NULL
                    AND fecha >= " . self::INICIO_PERIODO . " AT TIME ZONE :zona
                ORDER BY api_key";
        try {
            $consulta = $this->conexion()->prepare($sql);
            $consulta->execute([...$this->ventana($horas), 'servidor' => $servidor]);
            return [
                'error' => false,
                'datos' => $consulta->fetchAll(\PDO::FETCH_COLUMN)
            ];
        } catch (\PDOException $e) {
            return [
                'error' => true,
                'mensaje' => $e->getMessage()
            ];
        }
    }

    /**
     * Códigos de status que tienen accesos en el periodo, de menor a mayor.
     */
    public function statuses(string $servidor, int $horas = self::PERIODO_DEFECTO): array
    {
        $sql = "SELECT DISTINCT status
                FROM nginx_acceso
                WHERE servidor = :servidor
                    AND status IS NOT NULL
                    AND fecha >= " . self::INICIO_PERIODO . " AT TIME ZONE :zona
                ORDER BY status";
        try {
            $consulta = $this->conexion()->prepare($sql);
            $consulta->execute([...$this->ventana($horas), 'servidor' => $servidor]);
            return [
                'error' => false,
                'datos' => array_map('strval', $consulta->fetchAll(\PDO::FETCH_COLUMN))
            ];
        } catch (\PDOException $e) {
            return [
                'error' => true,
                'mensaje' => $e->getMessage()
            ];
        }
    }

    /**
     * Hosts que tienen accesos en el periodo, en orden alfabético.
     */
    public function hosts(string $servidor, int $horas = self::PERIODO_DEFECTO): array
    {
        $sql = "SELECT DISTINCT host
                FROM nginx_acceso
                WHERE servidor = :servidor
                    AND host IS NOT NULL
                    AND fecha >= " . self::INICIO_PERIODO . " AT TIME ZONE :zona
                ORDER BY host";
        try {
            $consulta = $this->conexion()->prepare($sql);
            $consulta->execute([...$this->ventana($horas), 'servidor' => $servidor]);
            return [
                'error' => false,
                'datos' => $consulta->fetchAll(\PDO::FETCH_COLUMN)
            ];
        } catch (\PDOException $e) {
            return [
                'error' => true,
                'mensaje' => $e->getMessage()
            ];
        }
    }

    /**
     * Accesos del periodo agrupados por intervalo, incluidos los intervalos sin accesos.
     * La etiqueta es la hora (HH) si el intervalo es de una hora, o HH:MI si es de minutos.
     */
    public function accesosPorHora(string $servidor, int $horas = self::PERIODO_DEFECTO, array $filtros = []): array
    {
        $sql = "SELECT to_char(h.hora, CASE WHEN CAST(:paso AS interval) >= interval '1 hour' THEN 'HH24' ELSE 'HH24:MI' END) AS hora,
                    count(a.id) AS cantidad
                FROM generate_series(
                    " . self::INICIO_PERIODO . ",
                    date_bin(CAST(:paso AS interval), now() AT TIME ZONE :zona, timestamp '2000-01-01'),
                    CAST(:paso AS interval)) AS h(hora)
                LEFT JOIN nginx_acceso a
                    ON a.servidor = :servidor
                    AND " . $this->condicionFiltros('a') . "
                    AND a.fecha >= h.hora AT TIME ZONE :zona
                    AND a.fecha < (h.hora + CAST(:paso AS interval)) AT TIME ZONE :zona
                GROUP BY h.hora
                ORDER BY h.hora";
        try {
            $consulta = $this->conexion()->prepare($sql);
            $consulta->execute([...$this->ventana($horas), 'servidor' => $servidor, ...$this->parametrosFiltros($filtros)]);
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
     * Últimos accesos del periodo, del más reciente al más antiguo.
     */
    public function ultimosAccesos(string $servidor, int $horas = self::PERIODO_DEFECTO, int $limite = 20, array $filtros = []): array
    {
        $sql = "SELECT id, to_char(fecha AT TIME ZONE :zona, 'YYYY-MM-DD HH24:MI:SS') AS fecha,
                    host, host(ip) AS ip, host(ip_real) AS ip_real, metodo,
                    ruta, parametros, referer, api_key, status, bytes, request_time
                FROM nginx_acceso
                WHERE servidor = :servidor
                    AND " . $this->condicionFiltros() . "
                    AND fecha >= " . self::INICIO_PERIODO . " AT TIME ZONE :zona
                ORDER BY fecha DESC
                LIMIT :limite";
        try {
            $consulta = $this->conexion()->prepare($sql);
            foreach ($this->ventana($horas) as $clave => $valor) {
                $consulta->bindValue($clave, $valor);
            }
            $consulta->bindValue('servidor', $servidor);
            $this->enlazarFiltros($consulta, $filtros);
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
     * Total de accesos por host en el periodo, del que más tiene al que menos.
     */
    public function accesosPorHost(string $servidor, int $horas = self::PERIODO_DEFECTO, array $filtros = []): array
    {
        $sql = "SELECT coalesce(host, '(sin host)') AS host, count(*) AS total
                FROM nginx_acceso
                WHERE servidor = :servidor
                    AND " . $this->condicionFiltros() . "
                    AND fecha >= " . self::INICIO_PERIODO . " AT TIME ZONE :zona
                GROUP BY host
                ORDER BY total DESC, host";
        try {
            $consulta = $this->conexion()->prepare($sql);
            $consulta->execute([...$this->ventana($horas), 'servidor' => $servidor, ...$this->parametrosFiltros($filtros)]);
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
     * IPs de cliente (ip_real) con más accesos en el periodo, con cuántos de ellos terminaron en
     * error (status >= 400) y la fecha del último acceso, para detectar bots o abusos. Los accesos sin
     * ip_real (anteriores a que Lantano la registrara) salen en una fila aparte con ip_real en NULL.
     */
    public function accesosPorIp(string $servidor, int $horas = self::PERIODO_DEFECTO, int $limite = 20, array $filtros = []): array
    {
        $sql = "SELECT host(ip_real) AS ip_real, count(*) AS total,
                    count(*) FILTER (WHERE status >= 400) AS errores,
                    to_char(max(fecha) AT TIME ZONE :zona, 'YYYY-MM-DD HH24:MI:SS') AS ultimo
                FROM nginx_acceso
                WHERE servidor = :servidor
                    AND " . $this->condicionFiltros() . "
                    AND fecha >= " . self::INICIO_PERIODO . " AT TIME ZONE :zona
                GROUP BY ip_real
                ORDER BY total DESC, ip_real
                LIMIT :limite";
        try {
            $consulta = $this->conexion()->prepare($sql);
            foreach ($this->ventana($horas) as $clave => $valor) {
                $consulta->bindValue($clave, $valor);
            }
            $consulta->bindValue('servidor', $servidor);
            $this->enlazarFiltros($consulta, $filtros);
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
     * Accesos del periodo por API key, del que más tiene al que menos, con cuántos terminaron
     * en error (status >= 400) y la fecha del último acceso. Los accesos sin API key salen
     * en una fila aparte con api_key en NULL.
     */
    public function accesosPorApiKey(string $servidor, int $horas = self::PERIODO_DEFECTO, array $filtros = []): array
    {
        $sql = "SELECT api_key, count(*) AS total,
                    count(*) FILTER (WHERE status >= 400) AS errores,
                    to_char(max(fecha) AT TIME ZONE :zona, 'YYYY-MM-DD HH24:MI:SS') AS ultimo
                FROM nginx_acceso
                WHERE servidor = :servidor
                    AND fecha >= " . self::INICIO_PERIODO . " AT TIME ZONE :zona
                    AND " . $this->condicionFiltros() . "
                GROUP BY api_key
                ORDER BY total DESC, api_key";
        try {
            $consulta = $this->conexion()->prepare($sql);
            $consulta->execute([...$this->ventana($horas), 'servidor' => $servidor, ...$this->parametrosFiltros($filtros)]);
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
     * Rutas con más accesos en el periodo. Los parámetros no cuentan, así que
     * /lista?page=1 y /lista?page=2 suman en la misma ruta.
     */
    public function accesosPorRuta(string $servidor, int $horas = self::PERIODO_DEFECTO, int $limite = 20, array $filtros = []): array
    {
        $sql = "SELECT coalesce(host, '(sin host)') AS host, ruta, count(*) AS total
                FROM nginx_acceso
                WHERE servidor = :servidor
                    AND " . $this->condicionFiltros() . "
                    AND fecha >= " . self::INICIO_PERIODO . " AT TIME ZONE :zona
                GROUP BY host, ruta
                ORDER BY total DESC, ruta
                LIMIT :limite";
        try {
            $consulta = $this->conexion()->prepare($sql);
            foreach ($this->ventana($horas) as $clave => $valor) {
                $consulta->bindValue($clave, $valor);
            }
            $consulta->bindValue('servidor', $servidor);
            $this->enlazarFiltros($consulta, $filtros);
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
