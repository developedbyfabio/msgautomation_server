<?php

namespace App\Ai;

/**
 * Fatia 14 — catalogo de TEMPLATES de conhecimento (KB da IA), em codigo
 * (padrao da Fatia 7). Conteudo PASSIVO com placeholders [editaveis]: a entrada
 * nao dispara nada sozinha (a IA e OFF por padrao e so a usa quando ligada),
 * entao nascer active=true e seguro. Sensitivity 'low' (conteudo publico).
 */
class KnowledgeTemplateCatalog
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
            'horario' => [
                'key' => 'horario',
                'name' => 'Horário de funcionamento',
                'description' => 'Dias e horários de atendimento, detalhados.',
                'content' => '[dias e horários detalhados — ex.: segunda a sexta das 8h às 18h; sábado das 8h às 12h; domingos e feriados fechado]',
            ],
            'endereco' => [
                'key' => 'endereco',
                'name' => 'Endereço e como chegar',
                'description' => 'Endereço, referências e estacionamento.',
                'content' => '[endereço completo, pontos de referência, orientações de como chegar e informações de estacionamento]',
            ],
            'pagamento' => [
                'key' => 'pagamento',
                'name' => 'Formas de pagamento',
                'description' => 'Meios de pagamento aceitos e parcelamento.',
                'content' => '[formas de pagamento aceitas — ex.: dinheiro, cartões de crédito/débito, Pix — e condições de parcelamento]',
            ],
            'cancelamento' => [
                'key' => 'cancelamento',
                'name' => 'Política de cancelamento/reagendamento',
                'description' => 'Regras e antecedência para cancelar ou remarcar.',
                'content' => '[regras de cancelamento e reagendamento — ex.: antecedência mínima, multas/taxas se houver, como solicitar]',
            ],
        ];
    }

    /** @throws \InvalidArgumentException se a key nao existir */
    public function get(string $key): array
    {
        $all = $this->all();
        if (! isset($all[$key])) {
            throw new \InvalidArgumentException("Template de conhecimento desconhecido: {$key}");
        }

        return $all[$key];
    }
}
