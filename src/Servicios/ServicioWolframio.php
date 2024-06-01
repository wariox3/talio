<?php

namespace App\Servicios;

class ServicioWolframio
{
    public function validarStatus(string $remoteHost, string $serviceName): string
    {
        $command = "ssh $remoteHost 'systemctl $serviceName status'";
        $status = shell_exec($command);
        return trim($status);
    }
}
