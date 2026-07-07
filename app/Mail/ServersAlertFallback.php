<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Servidores S3 (B4) — e-mail de FALLBACK quando o alerta nao pode ser entregue
 * pelo WhatsApp (Evolution fora, numero invalido, retries esgotados). Texto
 * simples: o objetivo e nao deixar o alerta sumir calado, nao ser bonito.
 */
class ServersAlertFallback extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $motivo) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Alerta de infraestrutura] Falha na notificacao por WhatsApp');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.servers-alert-fallback');
    }
}
