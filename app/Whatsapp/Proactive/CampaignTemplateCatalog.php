<?php

namespace App\Whatsapp\Proactive;

/**
 * Fatia 14 — catalogo de TEMPLATES de campanha proativa, em codigo (padrao da
 * Fatia 7). Toda campanha de template NASCE draft com publico VAZIO (o form de
 * edicao exige escolher tags/coluna/contatos antes de salvar/aprovar) e NUNCA
 * dispara nada — preview/aprovacao/disparo continuam sendo do ciclo P-2/P-3.
 * fallback_footer so e usado se a conta nao tiver rodape padrao configurado.
 */
class CampaignTemplateCatalog
{
    /** @return array<int,array{key:string,name:string,description:string}> resumo pra UI */
    public function summaries(): array
    {
        return array_values(array_map(fn (array $t) => [
            'key' => $t['key'], 'name' => $t['name'], 'description' => $t['description'],
        ], $this->all()));
    }

    /** @return array<string,array> todos os templates, indexados pela key */
    public function all(): array
    {
        return [
            'promocao' => [
                'key' => 'promocao',
                'name' => 'Promoção',
                'description' => 'Oferta especial com validade — edite o detalhe e a data.',
                'message' => 'Olá! Temos uma oferta especial: [detalhe da promoção]. Válida até [data]. Quer saber mais?',
            ],
            'comunicado' => [
                'key' => 'comunicado',
                'name' => 'Comunicado',
                'description' => 'Aviso geral: mudança de horário, feriado, novidade.',
                'message' => 'Olá! Informamos que [aviso: mudança de horário/feriado/novidade]. Qualquer dúvida, é só responder por aqui.',
            ],
            'reativacao' => [
                'key' => 'reativacao',
                'name' => 'Reativação',
                'description' => 'Reaproximação de quem sumiu, com convite/benefício.',
                'message' => 'Olá, sentimos sua falta! Faz tempo que não nos vemos — [convite/benefício para voltar].',
            ],
        ];
    }

    /** Rodape usado SO se a conta nao tiver o padrao configurado. */
    public function fallbackFooter(): string
    {
        return 'Se não quiser mais receber mensagens, responda {palavra_sair}.';
    }

    /** @throws \InvalidArgumentException se a key nao existir */
    public function get(string $key): array
    {
        $all = $this->all();
        if (! isset($all[$key])) {
            throw new \InvalidArgumentException("Template de campanha desconhecido: {$key}");
        }

        return $all[$key];
    }
}
