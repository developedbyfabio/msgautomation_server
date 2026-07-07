<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Servers\AgentToken;
use App\Servers\MetricsBuffer;
use App\Servers\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Servidores S4 — agente coletor: a FILTRAGEM DE DISCO (o problema que o df do
 * dono motivou), o payload casando com o schema da ingestao S1, e as rotas que
 * servem o instalador/coletor. Os testes rodam o SCRIPT BASH REAL (seam
 * AGENT_DF_INPUT) para provar o filtro de verdade, nao uma reimplementacao.
 */
class ServersColetorTest extends TestCase
{
    use RefreshDatabase;

    /** df -PT do laravel-dev (real): 4 tmpfs + 2 ext4 (/ e /srv) + 8 overlays do Docker. */
    private const DF_DONO = "Filesystem     Type     1024-blocks     Used Available Capacity Mounted on\n"
        ."tmpfs          tmpfs        1015808     2048   1013760       1% /run\n"
        ."/dev/xvda2     ext4        20961280 17825792   2211840      88% /\n"
        ."tmpfs          tmpfs        5138432        0   5138432       0% /dev/shm\n"
        ."tmpfs          tmpfs           5120        0      5120       0% /run/lock\n"
        ."/dev/xvda3     ext4        82837504  3670016  75497472       5% /srv\n"
        ."tmpfs          tmpfs        1015808        8   1015800       1% /run/user/0\n"
        ."overlay        overlay     20961280 17825792   2211840      88% /srv/docker/rootfs/overlayfs/aaa\n"
        ."overlay        overlay     20961280 17825792   2211840      88% /srv/docker/rootfs/overlayfs/bbb\n"
        ."overlay        overlay     20961280 17825792   2211840      88% /srv/docker/rootfs/overlayfs/ccc\n"
        ."overlay        overlay     20961280 17825792   2211840      88% /srv/docker/rootfs/overlayfs/ddd\n"
        ."overlay        overlay     20961280 17825792   2211840      88% /srv/docker/rootfs/overlayfs/eee\n"
        ."overlay        overlay     20961280 17825792   2211840      88% /srv/docker/rootfs/overlayfs/fff\n"
        ."overlay        overlay     20961280 17825792   2211840      88% /srv/docker/rootfs/overlayfs/ggg\n"
        ."overlay        overlay     20961280 17825792   2211840      88% /srv/docker/rootfs/overlayfs/hhh\n";

    private function agent(): string
    {
        return base_path('deploy/agent/msgautomation-agent.sh');
    }

    // ---- filtragem de disco (o teste que o df do dono motivou) ------------------

    public function test_filtra_pseudo_fs_e_deduplica_para_so_particoes_reais(): void
    {
        $r = Process::env(['AGENT_DF_INPUT' => self::DF_DONO])
            ->run('sh '.escapeshellarg($this->agent()).' --disks-only');

        $this->assertTrue($r->successful(), $r->errorOutput());
        $disks = json_decode('['.trim($r->output()).']', true);

        // 15 linhas de df -> APENAS 2 particoes reais (/ e /srv), uma vez cada.
        $this->assertCount(2, $disks);
        $mounts = array_column($disks, 'mount');
        $this->assertEqualsCanonicalizing(['/', '/srv'], $mounts);

        $raiz = collect($disks)->firstWhere('mount', '/');
        $this->assertSame(88.0, (float) $raiz['pct']);
        $srv = collect($disks)->firstWhere('mount', '/srv');
        $this->assertSame(5.0, (float) $srv['pct']);

        // Nenhum overlay/tmpfs vazou.
        $this->assertStringNotContainsString('overlayfs', $r->output());
        $this->assertStringNotContainsString('/run', $r->output());
    }

    // ---- payload casa com a ingestao S1 (end-to-end) ----------------------------

    public function test_payload_do_agente_e_aceito_pela_ingestao_e_cai_no_buffer(): void
    {
        $account = Account::create(['name' => 'A']);
        $server = Server::withoutAccountScope()->create(['account_id' => $account->id, 'name' => 'srv', 'os' => 'linux']);
        $token = app(AgentToken::class)->issue($server);

        // O agente monta o payload real (CPU/RAM/swap/load do host de teste +
        // discos filtrados do df do dono). --dry-run imprime o JSON, nao envia.
        $r = Process::env(['AGENT_DF_INPUT' => self::DF_DONO])
            ->run('sh '.escapeshellarg($this->agent()).' --dry-run');
        $this->assertTrue($r->successful(), $r->errorOutput());
        $payload = json_decode(trim($r->output()), true);
        $this->assertIsArray($payload);

        // O MESMO payload passa pela validacao da S1 (200) e enche o buffer.
        $this->postJson(route('webhook.servers.ingest'), $payload, ['X-Agent-Token' => $token])->assertOk();

        $sample = app(MetricsBuffer::class)->latest($server->id);
        $this->assertNotNull($sample);
        $mounts = array_column($sample['disks'], 'mount');
        $this->assertEqualsCanonicalizing(['/', '/srv'], $mounts);
        $this->assertArrayHasKey('cpu_pct', $sample);
        $this->assertArrayHasKey('mem_pct', $sample);
    }

    public function test_token_ausente_no_agente_e_401_no_endpoint(): void
    {
        // Reafirma a S1: sem token, a ingestao recusa (o agente sem config nao entrega).
        $this->postJson(route('webhook.servers.ingest'), ['cpu_pct' => 1, 'mem' => ['pct' => 1], 'disks' => [['mount' => '/', 'pct' => 1]]])
            ->assertStatus(401);
    }

    // ---- rotas que servem o instalador / coletor --------------------------------

    public function test_rota_do_instalador_compoe_o_agente_sem_placeholder(): void
    {
        $resp = $this->get(route('servidores.agente.instalar'));
        $resp->assertOk();
        $body = $resp->getContent();

        $this->assertStringNotContainsString('@@AGENT_SCRIPT@@', $body); // placeholder substituido
        $this->assertStringContainsString('agente coletor de metricas', $body); // agente embutido
        $this->assertStringContainsString('msgautomation-agent-uninstall', $body); // desinstalador
        $this->assertSame(2, substr_count($body, 'MSGAUTO_AGENT_EOF')); // heredoc integro
    }

    public function test_rota_do_coletor_serve_o_agente_cru(): void
    {
        $this->get(route('servidores.agente.coletor'))
            ->assertOk()
            ->assertSee('PUSH DE SAIDA', escape: false);
    }
}
