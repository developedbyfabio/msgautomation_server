<?php

namespace App\Variables;

/**
 * Fatia 14 — catalogo de TEMPLATES de variavel, em codigo (padrao da Fatia 7).
 * Todas 'static' com valor-placeholder [editavel]. O NOME da variavel e a sua
 * identidade de referencia ({empresa}) — por isso colisao NAO sufixa (uma
 * {empresa_2} nao seria a {empresa} que as mensagens referenciam): o caminho
 * oficial (VariableWriter) rejeita duplicata e a UI mostra o motivo.
 */
class VariableTemplateCatalog
{
    /** @return array<int,array{key:string,name:string,description:string}> resumo pra UI */
    public function summaries(): array
    {
        return array_values(array_map(fn (array $t) => [
            'key' => $t['key'], 'name' => '{' . $t['name'] . '}', 'description' => $t['description'],
        ], $this->all()));
    }

    /** @return array<string,array> todos os templates, indexados pela key */
    public function all(): array
    {
        return [
            'empresa' => [
                'key' => 'empresa',
                'name' => 'empresa',
                'description' => 'Nome da empresa — use {empresa} em regras, fluxos e campanhas.',
                'type' => 'static',
                'config' => ['valor' => '[Nome da empresa]'],
            ],
            'atendente' => [
                'key' => 'atendente',
                'name' => 'atendente',
                'description' => 'Nome do atendente — use {atendente} nas mensagens.',
                'type' => 'static',
                'config' => ['valor' => '[Nome do atendente]'],
            ],
            'horario_funcionamento' => [
                'key' => 'horario_funcionamento',
                'name' => 'horario_funcionamento',
                'description' => 'Horário de funcionamento — use {horario_funcionamento} nas mensagens.',
                'type' => 'static',
                'config' => ['valor' => '[Seg a Sex, 8h às 18h]'],
            ],
        ];
    }

    /** @throws \InvalidArgumentException se a key nao existir */
    public function get(string $key): array
    {
        $all = $this->all();
        if (! isset($all[$key])) {
            throw new \InvalidArgumentException("Template de variavel desconhecido: {$key}");
        }

        return $all[$key];
    }
}
