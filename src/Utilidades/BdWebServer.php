<?php

namespace App\Utilidades;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Consultas de solo lectura sobre las tablas acceso y error de la base de
 * Lantano (PostgreSQL), donde se guardan los access.log y los error.log de
 * los servidores web. La conexion se abre en la primera consulta, no al
 * construir el servicio.
 */
class BdWebServer
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
     * Servidores web de los que Lantano guarda el access.log (columna origen de acceso).
     */
    public const ORIGENES = [
        'nginx' => 'Nginx',
        'apache' => 'Apache',
    ];

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
     * Niveles de los error.log, del más grave al menos, ya normalizados: Apache y Monolog
     * escriben warning, critical y emergency donde nginx escribe warn, crit y emerg.
     */
    public const NIVELES = [
        'emerg' => 'Emergencia',
        'alert' => 'Alerta',
        'crit' => 'Crítico',
        'error' => 'Error',
        'warn' => 'Aviso',
        'notice' => 'Noticia',
        'info' => 'Info',
        'debug' => 'Debug',
    ];

    /**
     * Grupos de nivel que se pueden filtrar además de un nivel exacto. Sin niveles no filtra.
     */
    public const GRUPOS_NIVEL = [
        'todos' => ['niveles' => null, 'texto' => 'Todos los niveles'],
        'errores' => ['niveles' => ['emerg', 'alert', 'crit', 'error'], 'texto' => 'Errores (error y críticos)'],
        'avisos' => ['niveles' => ['emerg', 'alert', 'crit', 'error', 'warn'], 'texto' => 'Errores y avisos'],
    ];
    public const NIVEL_DEFECTO = 'todos';

    /**
     * Carpeta de la que cuelgan los sitios en los servidores Apache. El error.log de Apache no
     * dice el virtual host, pero las rutas de los mensajes de PHP y Symfony sí traen el sitio.
     */
    private const RAIZ_SITIOS = '/mnt/sitios/';

    /**
     * Inicio del periodo, en hora local: el inicio del intervalo actual menos los intervalos
     * restantes. Todas las consultas lo usan para que los totales coincidan con la gráfica.
     */
    private const INICIO_PERIODO = "(date_bin(CAST(:paso AS interval), now() AT TIME ZONE :zona, timestamp '2000-01-01')
                    - CAST(:paso AS interval) * (CAST(:puntos AS integer) - 1))";

    private ?\PDO $conexion = null;

    public function __construct(
        #[Autowire(env: 'DATABASE_BDLANTANO_URL')] private readonly string $urlBaseDatos,
    ) {
    }

    private function conexion(): \PDO
    {
        if ($this->conexion === null) {
            $url = parse_url($this->urlBaseDatos);
            if (!is_array($url) || !isset($url['host'], $url['user'], $url['path'])) {
                // PDOException para que cada consulta lo devuelva como error y no rompa la pagina.
                throw new \PDOException('DATABASE_BDLANTANO_URL no es una URL válida: postgresql://usuario:clave@host:5432/base');
            }
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
     * El alias es el de la tabla acceso cuando la consulta tiene joins.
     */
    private function condicionFiltros(string $alias = ''): string
    {
        $c = $alias === '' ? '' : $alias . '.';
        return "(CAST(:origen AS text) IS NULL OR {$c}origen = CAST(:origen AS text))
                    AND (CAST(:ip AS inet) IS NULL OR {$c}ip_real = CAST(:ip AS inet))
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
     * @param array{origen?: ?string, ip?: ?string, api_key?: ?string, ruta?: ?string, host?: ?string, status?: ?string} $filtros
     */
    private function parametrosFiltros(array $filtros): array
    {
        return [
            'origen' => $filtros['origen'] ?? null,
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
     * Servidores que tienen accesos o errores registrados, en orden alfabético.
     */
    public function servidores(): array
    {
        $sql = "SELECT servidor FROM acceso WHERE servidor IS NOT NULL
                UNION SELECT servidor FROM error WHERE servidor IS NOT NULL
                ORDER BY servidor";
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
                FROM acceso
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
                FROM acceso
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
                FROM acceso
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
                LEFT JOIN acceso a
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
                    origen, host, host(ip) AS ip, host(ip_real) AS ip_real, metodo,
                    ruta, parametros, referer, api_key, status, bytes, request_time
                FROM acceso
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
                FROM acceso
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
                FROM acceso
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
                FROM acceso
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
                FROM acceso
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

    /**
     * Nivel normalizado a las claves de NIVELES. El alias es el de la tabla error cuando la
     * consulta tiene joins.
     */
    private function nivelError(string $alias = ''): string
    {
        $c = $alias === '' ? '' : $alias . '.';
        return "(CASE {$c}nivel WHEN 'warning' THEN 'warn' WHEN 'critical' THEN 'crit'
                    WHEN 'emergency' THEN 'emerg' ELSE {$c}nivel END)";
    }

    /**
     * Sitio al que pertenece un error: el host si el log lo trae (nginx) o, si no, la carpeta
     * del sitio que aparece en el mensaje (Apache). Los que no dicen ninguno quedan en NULL.
     */
    private function sitioError(string $alias = ''): string
    {
        $c = $alias === '' ? '' : $alias . '.';
        return "coalesce({$c}host, substring({$c}mensaje from '" . self::RAIZ_SITIOS . "([^/ ]+)/'))";
    }

    /**
     * Condición SQL de los filtros opcionales de error: cada uno con su parámetro en NULL no filtra.
     * La IP es la del cliente que el log atribuye al error, y el texto se busca dentro del mensaje
     * sin distinguir mayúsculas.
     */
    private function condicionFiltrosError(string $alias = ''): string
    {
        $c = $alias === '' ? '' : $alias . '.';
        return "(CAST(:origen AS text) IS NULL OR {$c}origen = CAST(:origen AS text))
                    AND (CAST(:niveles AS text) IS NULL
                        OR " . $this->nivelError($alias) . " = ANY(string_to_array(CAST(:niveles AS text), ',')))
                    AND (CAST(:modulo AS text) IS NULL OR {$c}modulo = CAST(:modulo AS text))
                    AND (CAST(:sitio AS text) IS NULL OR " . $this->sitioError($alias) . " = CAST(:sitio AS text))
                    AND (CAST(:ip AS inet) IS NULL OR {$c}client = CAST(:ip AS inet))
                    AND (CAST(:texto AS text) IS NULL OR strpos(lower({$c}mensaje), lower(CAST(:texto AS text))) > 0)";
    }

    /**
     * Parámetros de los filtros opcionales de error; los que no vienen quedan en NULL.
     * El nivel es un grupo de GRUPOS_NIVEL o un nivel de NIVELES; sin él se usa el de defecto.
     *
     * @param array{origen?: ?string, nivel?: ?string, modulo?: ?string, sitio?: ?string, ip?: ?string, texto?: ?string} $filtros
     */
    private function parametrosFiltrosError(array $filtros): array
    {
        $nivel = $filtros['nivel'] ?? self::NIVEL_DEFECTO;
        $niveles = isset(self::NIVELES[$nivel]) ? [$nivel] : (self::GRUPOS_NIVEL[$nivel] ?? self::GRUPOS_NIVEL[self::NIVEL_DEFECTO])['niveles'];
        return [
            'origen' => $filtros['origen'] ?? null,
            'niveles' => $niveles === null ? null : implode(',', $niveles),
            'modulo' => $filtros['modulo'] ?? null,
            'sitio' => $filtros['sitio'] ?? null,
            'ip' => $filtros['ip'] ?? null,
            'texto' => $filtros['texto'] ?? null,
        ];
    }

    /**
     * Ejecuta una consulta de la tabla error y devuelve sus filas, o la primera columna.
     */
    private function consultarError(string $sql, array $parametros, bool $columna = false): array
    {
        try {
            $consulta = $this->conexion()->prepare($sql);
            $consulta->execute($parametros);
            return [
                'error' => false,
                'datos' => $columna ? $consulta->fetchAll(\PDO::FETCH_COLUMN) : $consulta->fetchAll()
            ];
        } catch (\PDOException $e) {
            return [
                'error' => true,
                'mensaje' => $e->getMessage()
            ];
        }
    }

    /**
     * Módulos que tienen errores en el periodo, de cualquier nivel, en orden alfabético.
     */
    public function modulosError(string $servidor, int $horas = self::PERIODO_DEFECTO): array
    {
        $sql = "SELECT DISTINCT modulo
                FROM error
                WHERE servidor = :servidor
                    AND modulo IS NOT NULL
                    AND fecha >= " . self::INICIO_PERIODO . " AT TIME ZONE :zona
                ORDER BY modulo";
        return $this->consultarError($sql, [...$this->ventana($horas), 'servidor' => $servidor], true);
    }

    /**
     * Sitios que tienen errores en el periodo, de cualquier nivel, en orden alfabético.
     */
    public function sitiosError(string $servidor, int $horas = self::PERIODO_DEFECTO): array
    {
        $sql = "SELECT DISTINCT " . $this->sitioError() . " AS sitio
                FROM error
                WHERE servidor = :servidor
                    AND " . $this->sitioError() . " IS NOT NULL
                    AND fecha >= " . self::INICIO_PERIODO . " AT TIME ZONE :zona
                ORDER BY sitio";
        return $this->consultarError($sql, [...$this->ventana($horas), 'servidor' => $servidor], true);
    }

    /**
     * Errores del periodo agrupados por intervalo, incluidos los intervalos sin errores.
     * La etiqueta es la hora (HH) si el intervalo es de una hora, o HH:MI si es de minutos.
     */
    public function erroresPorHora(string $servidor, int $horas = self::PERIODO_DEFECTO, array $filtros = []): array
    {
        $sql = "SELECT to_char(h.hora, CASE WHEN CAST(:paso AS interval) >= interval '1 hour' THEN 'HH24' ELSE 'HH24:MI' END) AS hora,
                    count(e.id) AS cantidad
                FROM generate_series(
                    " . self::INICIO_PERIODO . ",
                    date_bin(CAST(:paso AS interval), now() AT TIME ZONE :zona, timestamp '2000-01-01'),
                    CAST(:paso AS interval)) AS h(hora)
                LEFT JOIN error e
                    ON e.servidor = :servidor
                    AND " . $this->condicionFiltrosError('e') . "
                    AND e.fecha >= h.hora AT TIME ZONE :zona
                    AND e.fecha < (h.hora + CAST(:paso AS interval)) AT TIME ZONE :zona
                GROUP BY h.hora
                ORDER BY h.hora";
        return $this->consultarError($sql, [...$this->ventana($horas), 'servidor' => $servidor, ...$this->parametrosFiltrosError($filtros)]);
    }

    /**
     * Total de errores por nivel en el periodo, del que más tiene al que menos.
     */
    public function erroresPorNivel(string $servidor, int $horas = self::PERIODO_DEFECTO, array $filtros = []): array
    {
        $sql = "SELECT " . $this->nivelError() . " AS nivel, count(*) AS total,
                    to_char(max(fecha) AT TIME ZONE :zona, 'YYYY-MM-DD HH24:MI:SS') AS ultimo
                FROM error
                WHERE servidor = :servidor
                    AND " . $this->condicionFiltrosError() . "
                    AND fecha >= " . self::INICIO_PERIODO . " AT TIME ZONE :zona
                GROUP BY 1
                ORDER BY total DESC, nivel";
        return $this->consultarError($sql, [...$this->ventana($horas), 'servidor' => $servidor, ...$this->parametrosFiltrosError($filtros)]);
    }

    /**
     * Total de errores por módulo en el periodo, del que más tiene al que menos. Los que no
     * traen módulo (nginx) salen en una fila aparte con modulo en NULL.
     */
    public function erroresPorModulo(string $servidor, int $horas = self::PERIODO_DEFECTO, array $filtros = []): array
    {
        $sql = "SELECT modulo, count(*) AS total,
                    to_char(max(fecha) AT TIME ZONE :zona, 'YYYY-MM-DD HH24:MI:SS') AS ultimo
                FROM error
                WHERE servidor = :servidor
                    AND " . $this->condicionFiltrosError() . "
                    AND fecha >= " . self::INICIO_PERIODO . " AT TIME ZONE :zona
                GROUP BY modulo
                ORDER BY total DESC, modulo";
        return $this->consultarError($sql, [...$this->ventana($horas), 'servidor' => $servidor, ...$this->parametrosFiltrosError($filtros)]);
    }

    /**
     * Sitios con más errores en el periodo. Los errores que no dicen de qué sitio son salen
     * en una fila aparte con sitio en NULL.
     */
    public function erroresPorSitio(string $servidor, int $horas = self::PERIODO_DEFECTO, int $limite = 20, array $filtros = []): array
    {
        $sql = "SELECT " . $this->sitioError() . " AS sitio, count(*) AS total,
                    to_char(max(fecha) AT TIME ZONE :zona, 'YYYY-MM-DD HH24:MI:SS') AS ultimo
                FROM error
                WHERE servidor = :servidor
                    AND " . $this->condicionFiltrosError() . "
                    AND fecha >= " . self::INICIO_PERIODO . " AT TIME ZONE :zona
                GROUP BY 1
                ORDER BY total DESC, sitio
                LIMIT CAST(:limite AS integer)";
        return $this->consultarError($sql, [...$this->ventana($horas), 'servidor' => $servidor, 'limite' => $limite, ...$this->parametrosFiltrosError($filtros)]);
    }

    /**
     * IPs de cliente con más errores en el periodo, para detectar escáneres o abusos. Los
     * errores que el log no atribuye a un cliente salen en una fila aparte con ip en NULL.
     */
    public function erroresPorIp(string $servidor, int $horas = self::PERIODO_DEFECTO, int $limite = 20, array $filtros = []): array
    {
        $sql = "SELECT host(client) AS ip, count(*) AS total,
                    to_char(max(fecha) AT TIME ZONE :zona, 'YYYY-MM-DD HH24:MI:SS') AS ultimo
                FROM error
                WHERE servidor = :servidor
                    AND " . $this->condicionFiltrosError() . "
                    AND fecha >= " . self::INICIO_PERIODO . " AT TIME ZONE :zona
                GROUP BY client
                ORDER BY total DESC, client
                LIMIT CAST(:limite AS integer)";
        return $this->consultarError($sql, [...$this->ventana($horas), 'servidor' => $servidor, 'limite' => $limite, ...$this->parametrosFiltrosError($filtros)]);
    }

    /**
     * Errores que más se repiten en el periodo. Para que el mismo error cuente junto aunque
     * cambien sus datos, se agrupa por el inicio del mensaje con lo que va entre comillas y los
     * números borrados; de cada grupo se muestra el mensaje más reciente como ejemplo.
     */
    public function erroresPorMensaje(string $servidor, int $horas = self::PERIODO_DEFECTO, int $limite = 20, array $filtros = []): array
    {
        $sql = "SELECT nivel, modulo, count(*) AS total,
                    to_char(max(fecha) AT TIME ZONE :zona, 'YYYY-MM-DD HH24:MI:SS') AS ultimo,
                    left((array_agg(mensaje ORDER BY fecha DESC))[1], 400) AS ejemplo
                FROM (
                    SELECT " . $this->nivelError() . " AS nivel, modulo, fecha, mensaje,
                        left(regexp_replace(regexp_replace(mensaje, '\"[^\"]*\"', '\"\"', 'g'), '[0-9]+', '0', 'g'), 100) AS firma
                    FROM error
                    WHERE servidor = :servidor
                        AND " . $this->condicionFiltrosError() . "
                        AND fecha >= " . self::INICIO_PERIODO . " AT TIME ZONE :zona
                ) AS e
                GROUP BY nivel, modulo, firma
                ORDER BY total DESC, firma
                LIMIT CAST(:limite AS integer)";
        return $this->consultarError($sql, [...$this->ventana($horas), 'servidor' => $servidor, 'limite' => $limite, ...$this->parametrosFiltrosError($filtros)]);
    }

    /**
     * Últimos errores del periodo, del más reciente al más antiguo.
     */
    public function ultimosErrores(string $servidor, int $horas = self::PERIODO_DEFECTO, int $limite = 20, array $filtros = []): array
    {
        $sql = "SELECT id, to_char(fecha AT TIME ZONE :zona, 'YYYY-MM-DD HH24:MI:SS') AS fecha,
                    origen, " . $this->nivelError() . " AS nivel, modulo, " . $this->sitioError() . " AS sitio,
                    host(client) AS ip, mensaje, request, upstream, referer
                FROM error
                WHERE servidor = :servidor
                    AND " . $this->condicionFiltrosError() . "
                    AND fecha >= " . self::INICIO_PERIODO . " AT TIME ZONE :zona
                ORDER BY fecha DESC
                LIMIT CAST(:limite AS integer)";
        return $this->consultarError($sql, [...$this->ventana($horas), 'servidor' => $servidor, 'limite' => $limite, ...$this->parametrosFiltrosError($filtros)]);
    }
}
