<?php

namespace App\Mail;

use App\Models\EventosSignificativo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de urgencia al administrador cuando un evento significativo
 * (contingencia offline) supera el plazo normativo para terminar de enviar
 * su paquete al SIAT — 48h para el paquete offline normal, 72h para el
 * paquete CAFC (ver EventosSignificativo::excedioPlazoEnvioPaquete() /
 * excedioPlazoEnvioCafc() y el comando siat:alertar-plazos que dispara esto).
 */
class AlertaPlazoContingenciaMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public EventosSignificativo $evento,
        public string $tipoPlazo, // 'paquete' | 'cafc'
        public int $limiteHoras,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "URGENTE: Evento #{$this->evento->eveid} superó el plazo de envío ({$this->limiteHoras}h) — {$this->evento->emisor->emirazsoc}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.alerta-plazo-contingencia');
    }
}
