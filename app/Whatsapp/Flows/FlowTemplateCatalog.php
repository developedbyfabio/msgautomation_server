<?php

namespace App\Whatsapp\Flows;

/**
 * Fatia 7 — catalogo de TEMPLATES de fluxo de atendimento (clinica, salao, comercio).
 * Catalogo em CODIGO (nao e dado de tenant): versionavel, zero migration. Cada
 * blueprint descreve nome, gatilhos de entrada e a arvore de nos (menu/final/handoff)
 * que o InstantiateFlowTemplate materializa como um Flow NORMAL da conta ativa
 * (editavel no editor como qualquer fluxo; nasce enabled=true, pronto pra usar).
 *
 * Adicionar um template novo = adicionar uma entrada em all() (um metodo privado
 * com o blueprint). Formato do no:
 *   ['kind' => menu|final|handoff, 'message' => string,
 *    'options' => [['input','label','node' => <no filho>], ...]]
 * (final/handoff sao terminais — sem 'options'; handoff exige message, como o
 * editor da 5b valida. Todo template e um MENU com opcoes — achado da Fatia 4:
 * menu sem opcao vira saudacao de um tiro.)
 */
class FlowTemplateCatalog
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
            'clinica' => $this->clinica(),
            'salao' => $this->salao(),
            'comercio' => $this->comercio(),
        ];
    }

    /** @throws \InvalidArgumentException se a key nao existir */
    public function get(string $key): array
    {
        $all = $this->all();
        if (! isset($all[$key])) {
            throw new \InvalidArgumentException("Template de fluxo desconhecido: {$key}");
        }

        return $all[$key];
    }

    private function clinica(): array
    {
        return [
            'key' => 'clinica',
            'name' => 'Clínica / consultório',
            'description' => 'Menu de agendamento, convênios, endereço e horários — agendar e atendente caem pra humano.',
            'timeout_seconds' => 600,
            'invalid_message' => 'Não entendi. Responda com o número de uma das opções do menu.',
            'triggers' => [
                ['type' => 'contains', 'value' => 'menu'],
                ['type' => 'contains', 'value' => 'consulta'],
            ],
            'root' => [
                'kind' => 'menu',
                'message' => "Olá! Seja bem-vindo(a) à nossa clínica. Como podemos ajudar? Responda com o número:\n1 - Agendar consulta\n2 - Convênios e valores\n3 - Endereço e horário de atendimento\n4 - Falar com um atendente",
                'options' => [
                    ['input' => '1', 'label' => 'Agendar consulta', 'node' => [
                        'kind' => 'handoff',
                        'message' => 'Perfeito! Vou te transferir para um atendente que finaliza seu agendamento. Só um instante.',
                    ]],
                    ['input' => '2', 'label' => 'Convênios e valores', 'node' => [
                        'kind' => 'final',
                        'message' => "Atendemos os principais convênios e também consultas particulares. Para valores atualizados e convênios específicos, escolha 'Falar com um atendente'. É só mandar uma nova mensagem para voltar ao menu.",
                    ]],
                    ['input' => '3', 'label' => 'Endereço e horário de atendimento', 'node' => [
                        'kind' => 'final',
                        'message' => 'Estamos na [endereço]. Atendimento de segunda a sexta, das 8h às 18h. Envie uma nova mensagem para voltar ao menu.',
                    ]],
                    ['input' => '4', 'label' => 'Falar com um atendente', 'node' => [
                        'kind' => 'handoff',
                        'message' => 'Certo! Um atendente vai continuar seu atendimento em instantes. Aguarde, por favor.',
                    ]],
                ],
            ],
        ];
    }

    private function salao(): array
    {
        return [
            'key' => 'salao',
            'name' => 'Salão de beleza / barbearia',
            'description' => 'Menu de agendamento, serviços e preços, localização — agendar e atendente caem pra humano.',
            'timeout_seconds' => 600,
            'invalid_message' => 'Não entendi. Responda com o número de uma das opções do menu.',
            'triggers' => [
                ['type' => 'contains', 'value' => 'menu'],
                ['type' => 'contains', 'value' => 'agendar'],
            ],
            'root' => [
                'kind' => 'menu',
                'message' => "Oi! Que bom te ver por aqui. Como podemos ajudar? Responda com o número:\n1 - Agendar horário\n2 - Serviços e preços\n3 - Localização e funcionamento\n4 - Falar com atendente",
                'options' => [
                    ['input' => '1', 'label' => 'Agendar horário', 'node' => [
                        'kind' => 'handoff',
                        'message' => 'Ótimo! Vou te passar para alguém da equipe confirmar o melhor horário para você. Só um instante.',
                    ]],
                    ['input' => '2', 'label' => 'Serviços e preços', 'node' => [
                        'kind' => 'final',
                        'message' => "Nossos principais serviços:\nCorte — a partir de R$ 50\nEscova — a partir de R$ 40\nColoração — a partir de R$ 120\nManicure — a partir de R$ 35\nPara outros serviços e pacotes, escolha 'Falar com atendente'. Envie uma nova mensagem para voltar ao menu.",
                    ]],
                    ['input' => '3', 'label' => 'Localização e funcionamento', 'node' => [
                        'kind' => 'final',
                        'message' => 'Estamos na [endereço]. Funcionamos de terça a sábado, das 9h às 19h. Envie uma nova mensagem para voltar ao menu.',
                    ]],
                    ['input' => '4', 'label' => 'Falar com atendente', 'node' => [
                        'kind' => 'handoff',
                        'message' => 'Combinado! Alguém da equipe vai continuar seu atendimento em instantes. Aguarde, por favor.',
                    ]],
                ],
            ],
        ];
    }

    private function comercio(): array
    {
        return [
            'key' => 'comercio',
            'name' => 'Comércio / estabelecimento',
            'description' => 'Menu de horários, produtos e como comprar — pedido e atendente caem pra humano.',
            'timeout_seconds' => 600,
            'invalid_message' => 'Não entendi. Responda com o número de uma das opções do menu.',
            'triggers' => [
                ['type' => 'contains', 'value' => 'menu'],
                ['type' => 'contains', 'value' => 'comprar'],
            ],
            'root' => [
                'kind' => 'menu',
                'message' => "Olá! Obrigado pelo contato. Como podemos ajudar? Responda com o número:\n1 - Horário de funcionamento\n2 - Nossos produtos\n3 - Como comprar / fazer pedido\n4 - Falar com atendente",
                'options' => [
                    ['input' => '1', 'label' => 'Horário de funcionamento', 'node' => [
                        'kind' => 'final',
                        'message' => 'Funcionamos de segunda a sexta, das 8h às 18h, e aos sábados das 8h às 13h. Envie uma nova mensagem para voltar ao menu.',
                    ]],
                    ['input' => '2', 'label' => 'Nossos produtos', 'node' => [
                        'kind' => 'final',
                        'message' => "Trabalhamos com [linhas de produtos]. Para conhecer o catálogo completo ou tirar dúvidas sobre um item, escolha 'Falar com atendente'. Envie uma nova mensagem para voltar ao menu.",
                    ]],
                    ['input' => '3', 'label' => 'Como comprar / fazer pedido', 'node' => [
                        'kind' => 'handoff',
                        'message' => 'Perfeito! Um atendente vai montar seu pedido e já te responde por aqui. Só um instante.',
                    ]],
                    ['input' => '4', 'label' => 'Falar com atendente', 'node' => [
                        'kind' => 'handoff',
                        'message' => 'Certo! Um atendente vai continuar seu atendimento em instantes. Aguarde, por favor.',
                    ]],
                ],
            ],
        ];
    }
}
