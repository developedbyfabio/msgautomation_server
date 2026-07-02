<?php

namespace App\Livewire;

use App\Models\Contact;
use App\Models\Tag;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Component;

/**
 * T-1 — chips de tags do CONTATO (componente filho reutilizavel: painel do contato
 * em /contatos e /conversas). Adicionar com autocomplete + criar na hora; remover
 * pelo X (humano pode remover tag automatica). Origem rastreada no tooltip.
 * Tags NUNCA enviam nada — segmentam.
 */
class ContactTags extends Component
{
    public int $contactId;

    public string $tagInput = '';

    public function addTag(): void
    {
        $nome = trim($this->tagInput);
        if ($nome === '' || mb_strlen($nome) > 40) {
            return;
        }

        $contact = Contact::query()->find($this->contactId);
        if (! $contact) {
            return;
        }

        // Reusa por nome (case-insensitive) ou cria na hora.
        $tag = Tag::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($nome, 'UTF-8')])->first()
            ?? Tag::create(['name' => $nome, 'color' => 'zinc']);

        try {
            $contact->tags()->attach($tag->id, ['origin' => 'manual', 'origin_ref' => null]);
        } catch (UniqueConstraintViolationException) {
            // Ja aplicada -> no-op.
        }

        $this->tagInput = '';
        $this->dispatch('toast', message: 'Tag "' . $tag->name . '" aplicada.');
    }

    /** Aplica uma tag existente (clique na sugestao). */
    public function attachExisting(int $tagId): void
    {
        $contact = Contact::query()->find($this->contactId);
        $tag = Tag::query()->find($tagId);
        if (! $contact || ! $tag) {
            return;
        }

        try {
            $contact->tags()->attach($tag->id, ['origin' => 'manual', 'origin_ref' => null]);
        } catch (UniqueConstraintViolationException) {
        }

        $this->tagInput = '';
    }

    public function removeTag(int $tagId): void
    {
        Contact::query()->find($this->contactId)?->tags()->detach($tagId);
        $this->dispatch('toast', message: 'Tag removida.');
    }

    public function render()
    {
        $contact = Contact::query()->with('tags')->find($this->contactId);
        $atuais = $contact?->tags ?? collect();

        // Sugestoes: tags da conta que o contato ainda nao tem, filtradas pelo input.
        $busca = mb_strtolower(trim($this->tagInput), 'UTF-8');
        $sugestoes = ($busca !== '')
            ? Tag::query()->whereNotIn('id', $atuais->pluck('id'))
                ->whereRaw('LOWER(name) LIKE ?', ['%' . $busca . '%'])
                ->orderBy('name')->limit(6)->get()
            : collect();

        return view('livewire.contact-tags', [
            'atuais' => $atuais,
            'sugestoes' => $sugestoes,
        ]);
    }
}
