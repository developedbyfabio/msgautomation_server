<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Servidores S4 — serve o INSTALADOR do agente coletor (o `curl | sh` do
 * tutorial). Publico e SEM SEGREDO: o script versionado nao contem token
 * (AGENT_URL/AGENT_TOKEN vem por env na hora de instalar, param no config
 * restrito). O instalador embute o agente verbatim (arquivo unico versionado
 * em deploy/agent/) — o dono pode inspecionar antes de rodar.
 *
 * Em producao real o app deve ser servido por HTTPS (o dono decide a
 * exposicao; nao mexemos em Tunnel/nginx). Em DEV a LAN e http — aceitavel
 * para validacao, registrado no relatorio.
 */
class ServerAgentController extends Controller
{
    /** Instalador com o agente embutido (text/plain para `curl | sh`). */
    public function installer(): Response
    {
        $agente = (string) file_get_contents(base_path('deploy/agent/msgautomation-agent.sh'));
        $instalador = (string) file_get_contents(base_path('deploy/agent/install.sh'));

        // Embute o agente verbatim no lugar do marcador (dentro do heredoc do
        // instalador). O agente nunca contem a linha-marcador do heredoc.
        $composto = str_replace('@@AGENT_SCRIPT@@', rtrim($agente, "\n"), $instalador);

        return response($composto, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** O agente cru (para inspecao / download-inspect-run, alternativa segura ao pipe). */
    public function collector(): Response
    {
        return response((string) file_get_contents(base_path('deploy/agent/msgautomation-agent.sh')), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
