<?php

namespace App\Whatsapp\Proactive;

use App\Models\AutoReplySetting;
use App\Models\ProactiveCampaign;

/**
 * Fatia 14 — materializa um template de campanha na conta ativa como RASCUNHO
 * LIMPO: status=draft, start_at/aprovacao nulos, publico VAZIO (audience 'tags'
 * sem tags — o form exige definir antes de salvar/aprovar), rodape opt-out da
 * CONTA (mesmo pre-preenchimento do Campanhas::novo; fallback do catalogo se a
 * conta nao tiver). Mesmo caminho de criacao draft do CRUD/seed/Fatia 13
 * (ProactiveCampaign::create) — NUNCA despacha job, NUNCA agenda.
 */
class InstantiateCampaignTemplate
{
    public function __construct(private CampaignTemplateCatalog $catalog)
    {
    }

    /** @throws \InvalidArgumentException key desconhecida */
    public function handle(string $key, int $accountId): ProactiveCampaign
    {
        $template = $this->catalog->get($key);

        $footer = trim((string) AutoReplySetting::firstOrCreate(['account_id' => $accountId])->proactive_optout_footer);

        return ProactiveCampaign::create([
            'account_id' => $accountId,
            'name' => $this->uniqueName($template['name'], $accountId),
            'message' => $template['message'],
            'optout_footer' => $footer !== '' ? $footer : $this->catalog->fallbackFooter(),
            'audience_type' => 'tags',
            'audience_config' => ['tag_ids' => []], // publico e escolha do usuario, no form
            'status' => 'draft', // NUNCA alem de draft
            'start_at' => null,
            'approved_at' => null,
            'approved_by' => null,
        ]);
    }

    /** MESMO mecanismo da Fatia 7: sufixo " (2)", " (3)"... em colisao na conta. */
    private function uniqueName(string $base, int $accountId): string
    {
        $nome = $base;
        $n = 2;
        while (ProactiveCampaign::withoutAccountScope()->where('account_id', $accountId)->where('name', $nome)->exists()) {
            $nome = "{$base} ({$n})";
            $n++;
        }

        return $nome;
    }
}
