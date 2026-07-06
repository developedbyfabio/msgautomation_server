<?php

namespace App\Whatsapp\AutoReply;

/**
 * Fatia 14 — catalogo de TEMPLATES de regra (espelho do FlowTemplateCatalog da
 * Fatia 7): em CODIGO, versionavel, zero migration. Cada template descreve
 * gatilhos + resposta com placeholders EDITAVEIS entre colchetes ([nome da
 * empresa], [endereco completo]...) — por isso toda regra de template NASCE
 * DESABILITADA (regra ativa respondendo placeholder e inaceitavel; o usuario
 * troca os textos e liga). Regra NAO tem nome proprio no model — 'name' aqui e
 * so rotulo de UI do catalogo.
 */
class RuleTemplateCatalog
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
            'boas_vindas' => [
                'key' => 'boas_vindas',
                'name' => 'Boas-vindas',
                'description' => 'Responde saudações (oi, olá, bom dia...) com uma recepção da empresa.',
                'triggers' => [
                    ['type' => 'exact', 'value' => 'oi'],
                    ['type' => 'exact', 'value' => 'olá'],
                    ['type' => 'exact', 'value' => 'bom dia'],
                    ['type' => 'exact', 'value' => 'boa tarde'],
                    ['type' => 'exact', 'value' => 'boa noite'],
                ],
                'responses' => ['Olá! Seja bem-vindo(a) à [nome da empresa]. Como posso ajudar?'],
            ],
            'horario' => [
                'key' => 'horario',
                'name' => 'Horário de funcionamento',
                'description' => 'Responde perguntas sobre horário e funcionamento.',
                'triggers' => [
                    ['type' => 'contains', 'value' => 'horário'],
                    ['type' => 'contains', 'value' => 'que horas'],
                    ['type' => 'contains', 'value' => 'funcionamento'],
                ],
                'responses' => ['Nosso horário: [dias e horários]. Fora desse horário, deixe sua mensagem que respondemos assim que possível.'],
            ],
            'endereco' => [
                'key' => 'endereco',
                'name' => 'Endereço',
                'description' => 'Responde onde fica o estabelecimento e como chegar.',
                'triggers' => [
                    ['type' => 'contains', 'value' => 'endereço'],
                    ['type' => 'contains', 'value' => 'onde fica'],
                    ['type' => 'contains', 'value' => 'localização'],
                ],
                'responses' => ['Estamos na [endereço completo]. [referência/como chegar]'],
            ],
            'precos' => [
                'key' => 'precos',
                'name' => 'Preços',
                'description' => 'Responde perguntas sobre preço e valores.',
                'triggers' => [
                    ['type' => 'contains', 'value' => 'preço'],
                    ['type' => 'contains', 'value' => 'valor'],
                    ['type' => 'contains', 'value' => 'quanto custa'],
                ],
                'responses' => ['Sobre valores: [informação de preços ou orientação de como pedir um orçamento].'],
            ],
            'agradecimento' => [
                'key' => 'agradecimento',
                'name' => 'Agradecimento',
                'description' => 'Responde "obrigado" com gentileza (sem placeholder — pronta pra usar).',
                'triggers' => [
                    ['type' => 'exact', 'value' => 'obrigado'],
                    ['type' => 'exact', 'value' => 'obrigada'],
                    ['type' => 'exact', 'value' => 'valeu'],
                ],
                'responses' => ['Por nada! Se precisar de algo mais, é só chamar.'],
            ],
        ];
    }

    /** @throws \InvalidArgumentException se a key nao existir */
    public function get(string $key): array
    {
        $all = $this->all();
        if (! isset($all[$key])) {
            throw new \InvalidArgumentException("Template de regra desconhecido: {$key}");
        }

        return $all[$key];
    }
}
