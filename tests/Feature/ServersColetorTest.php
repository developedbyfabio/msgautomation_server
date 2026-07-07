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

    /**
     * df -PT do API-ECOMMERCE (o servidor real que revelou o bug): LVM ext4
     * (/, /boot, /srv) + devtmpfs/tmpfs + 2 overlays Docker + squashfs snap +
     * 2 CIFS (um a 100%) + uma linha de MOUNT MORTO que vazou pro stdout.
     */
    private const DF_API_ECOMMERCE = "Filesystem                       Type     1024-blocks      Used Available Capacity Mounted on\n"
        ."udev                             devtmpfs     8123456         0   8123456       0% /dev\n"
        ."tmpfs                            tmpfs        1638400     12345   1626055       1% /run\n"
        ."/dev/mapper/vg0-root             ext4        51475068  47000000   1875068      97% /\n"
        ."/dev/sda2                        ext4          999320    234567    696000      24% /boot\n"
        ."/dev/mapper/vg0-srv              ext4        41284928  30000000   9200000      76% /srv\n"
        ."overlay                          overlay     51475068  47000000   1875068      97% /var/lib/docker/overlay2/abc/merged\n"
        ."overlay                          overlay     51475068  47000000   1875068      97% /var/lib/docker/overlay2/def/merged\n"
        ."/dev/loop0                       squashfs       56192     56192         0     100% /snap/core20/2000\n"
        ."//192.168.10.1/Backup-Ecommerce  cifs      1048576000 500000000 548576000      48% /mnt/backup\n"
        ."//10.40.132.16/Fileserver/nf     cifs       209715200 209715200         0     100% /mnt/notas_fiscais\n"
        ."tmpfs                            tmpfs        1638400         0   1638400       0% /run/user/1000\n"
        ."df: /mnt/fotos: Host is down\n";

    private function agent(): string
    {
        return base_path('deploy/agent/msgautomation-agent.sh');
    }

    private function disksFrom(string $dfInput): array
    {
        $r = Process::env(['AGENT_DF_INPUT' => $dfInput])
            ->run('sh '.escapeshellarg($this->agent()).' --disks-only');
        $this->assertTrue($r->successful(), $r->errorOutput());
        $out = trim($r->output());

        return $out === '' ? [] : json_decode('['.$out.']', true);
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

    // ---- o bug do API-ECOMMERCE (df com mount morto + CIFS + so pseudo-FS) -------

    public function test_amostra_api_ecommerce_so_particoes_locais_reais(): void
    {
        $disks = $this->disksFrom(self::DF_API_ECOMMERCE);

        // Exatamente /, /boot, /srv (os 3 ext4). NUNCA vazio, nunca CIFS/overlay/squashfs.
        $mounts = array_column($disks, 'mount');
        $this->assertEqualsCanonicalizing(['/', '/boot', '/srv'], $mounts);

        $this->assertSame(97.0, (float) collect($disks)->firstWhere('mount', '/')['pct']);
        $this->assertSame(24.0, (float) collect($disks)->firstWhere('mount', '/boot')['pct']);
        $this->assertSame(76.0, (float) collect($disks)->firstWhere('mount', '/srv')['pct']);
    }

    public function test_cifs_a_100_e_ignorado(): void
    {
        $disks = $this->disksFrom(self::DF_API_ECOMMERCE);
        $mounts = array_column($disks, 'mount');

        // O fileserver CIFS a 100% (notas_fiscais) e o backup CIFS NAO viram alerta.
        $this->assertNotContains('/mnt/notas_fiscais', $mounts);
        $this->assertNotContains('/mnt/backup', $mounts);
        // Nenhum disco monitorado a 100% (o unico 100% era o CIFS, agora fora).
        $this->assertEmpty(array_filter($disks, fn ($d) => (float) $d['pct'] >= 100.0));
    }

    public function test_mount_morto_nao_trava_nem_zera_reporta_os_locais(): void
    {
        // A linha "df: /mnt/fotos: Host is down" (erro que vazou pro stdout) NAO
        // pode quebrar o parsing nem zerar a lista — os locais seguem reportados.
        $disks = $this->disksFrom(self::DF_API_ECOMMERCE);
        $this->assertNotEmpty($disks);
        $this->assertCount(3, $disks);
    }

    public function test_so_pseudo_fs_degrada_para_lista_vazia_sem_morrer(): void
    {
        // Cenario extremo: nenhum disco local (so tmpfs/overlay/cifs). O coletor
        // NAO morre (exit 0) e devolve lista vazia — o endpoint tolera (sem 422).
        $dfSoPseudo = "Filesystem Type 1024-blocks Used Available Capacity Mounted on\n"
            ."tmpfs tmpfs 1015808 2048 1013760 1% /run\n"
            ."overlay overlay 20961280 100 20961180 1% /var/lib/docker/x\n"
            ."//srv/share cifs 1048576 1048000 576 100% /mnt/net\n";

        $r = Process::env(['AGENT_DF_INPUT' => $dfSoPseudo])
            ->run('sh '.escapeshellarg($this->agent()).' --disks-only');
        $this->assertTrue($r->successful()); // exit 0 — nunca morre por falta de disco
        $this->assertSame('', trim($r->output()));
    }

    // ---- comando de update: troca o script, PRESERVA o config -------------------

    public function test_update_troca_o_binario_e_preserva_o_config(): void
    {
        $dir = sys_get_temp_dir().'/msgauto-upd-'.uniqid();
        mkdir($dir);
        $bin = $dir.'/msgautomation-agent';
        $config = $dir.'/config';
        $novo = $dir.'/coletor-novo.sh';

        // Estado "ja instalado": binario ANTIGO + config com token e URL.
        file_put_contents($bin, "#!/bin/sh\n# agente ANTIGO\necho old\n");
        chmod($bin, 0755);
        file_put_contents($config,
            "AGENT_URL=http://x/webhook/servers/ingest\n"
            ."AGENT_TOKEN=segredo-do-servidor\n"
            ."AGENT_COLLECTOR_URL=file://".$novo."\n");
        // Versao NOVA servida (sanidade do update exige #!/bin/sh + 'msgautomation').
        file_put_contents($novo, "#!/bin/sh\n# agente NOVO msgautomation v2\necho new\n");

        $r = Process::env(['AGENT_CONFIG' => $config, 'AGENT_BIN' => $bin])
            ->run('sh '.escapeshellarg($this->agent()).' --update');

        $this->assertTrue($r->successful(), $r->errorOutput());
        // Binario trocado pela versao nova...
        $this->assertStringContainsString('agente NOVO', file_get_contents($bin));
        $this->assertStringNotContainsString('agente ANTIGO', file_get_contents($bin));
        // ...e o CONFIG (token + URL) intacto.
        $conf = file_get_contents($config);
        $this->assertStringContainsString('AGENT_TOKEN=segredo-do-servidor', $conf);
        $this->assertStringContainsString('AGENT_URL=http://x/webhook/servers/ingest', $conf);
    }

    public function test_update_recusa_download_invalido_e_mantem_o_binario(): void
    {
        $dir = sys_get_temp_dir().'/msgauto-upd-'.uniqid();
        mkdir($dir);
        $bin = $dir.'/msgautomation-agent';
        $config = $dir.'/config';
        $lixo = $dir.'/pagina-de-erro.html';

        file_put_contents($bin, "#!/bin/sh\n# agente ANTIGO msgautomation\necho old\n");
        chmod($bin, 0755);
        file_put_contents($config, "AGENT_URL=http://x/webhook/servers/ingest\nAGENT_TOKEN=t\nAGENT_COLLECTOR_URL=file://".$lixo."\n");
        // "download" invalido: um HTML de erro/login, NAO o agente.
        file_put_contents($lixo, "<!doctype html><html><body>login</body></html>\n");

        $r = Process::env(['AGENT_CONFIG' => $config, 'AGENT_BIN' => $bin])
            ->run('sh '.escapeshellarg($this->agent()).' --update');

        $this->assertFalse($r->successful()); // recusa
        // Binario ANTIGO preservado (nao gravou o lixo).
        $this->assertStringContainsString('agente ANTIGO', file_get_contents($bin));
        $this->assertStringNotContainsString('doctype', file_get_contents($bin));
    }

    public function test_agente_reporta_a_versao(): void
    {
        $r = Process::run('sh '.escapeshellarg($this->agent()).' --version');
        $this->assertTrue($r->successful());
        $this->assertSame('2', trim($r->output()));
    }
}
