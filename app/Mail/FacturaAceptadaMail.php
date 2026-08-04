<?php

namespace App\Mail;

use App\Models\Factura;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Correo automático al comprador cuando su factura queda 'aceptada' (en
 * línea, o validada después de una contingencia offline/CAFC). Adjunta el
 * XML tal cual se envió al SIAT — sin representación gráfica en PDF por
 * ahora, solo el link de verificación y las leyendas como texto.
 */
class FacturaAceptadaMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Factura $factura)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Factura N° {$this->factura->facnro} - {$this->factura->emisor->emirazsoc}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.factura-aceptada');
    }

    /**
     * @return Attachment[]
     */
    public function attachments(): array
    {
        if (!$this->factura->facxmlpath || !Storage::disk('local')->exists($this->factura->facxmlpath)) {
            return [];
        }

        $xml = gzdecode(Storage::disk('local')->get($this->factura->facxmlpath));

        return [
            Attachment::fromData(fn () => $xml, "{$this->factura->faccuf}.xml")
                ->withMime('application/xml'),
        ];
    }
}
