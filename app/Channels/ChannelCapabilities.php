<?php

namespace App\Channels;

/**
 * CH-1 — capacidades DECLARADAS de um provedor de canal. O resto do sistema
 * CONSULTA (nunca assume): grupos existem? mensagem livre fora da janela de 24h
 * pode? proativa de texto livre pode? conexao e por QR? envia template?
 *
 * Evolution: grupos SIM, livre SIM, proativa SIM, QR SIM, template NAO.
 * Cloud API (CH-2): grupos NAO, livre NAO (24h), proativa NAO (so template), QR NAO.
 */
final class ChannelCapabilities
{
    public function __construct(
        public readonly bool $grupos,
        public readonly bool $mensagemLivreForaDaJanela,
        public readonly bool $proativaLivre,
        public readonly bool $qr,
        public readonly bool $template,
    ) {
    }
}
