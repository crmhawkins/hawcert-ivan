<?php

namespace App\Mail;

use App\Models\Asesoria;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

class InformeTrimestralAsesoria extends Mailable
{
    use Queueable, SerializesModels;

    public Asesoria $asesoria;
    public int $trimestre;
    public int $anio;
    public array $archivosAdjuntos;
    public ?string $enlaceZip;

    /**
     * Create a new message instance.
     */
    public function __construct(Asesoria $asesoria, int $trimestre, int $anio, array $archivosAdjuntos = [], ?string $enlaceZip = null)
    {
        $this->asesoria = $asesoria;
        $this->trimestre = $trimestre;
        $this->anio = $anio;
        $this->archivosAdjuntos = $archivosAdjuntos;
        $this->enlaceZip = $enlaceZip;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Hawkins Suites - Informe Trimestral Q{$this->trimestre} {$this->anio}",
            cc: [new Address('david@hawkins.es', 'David Hawkins')],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.informe-trimestral',
            with: [
                'asesoria' => $this->asesoria,
                'trimestre' => $this->trimestre,
                'anio' => $this->anio,
                'enlaceZip' => $this->enlaceZip,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];

        foreach ($this->archivosAdjuntos as $archivo) {
            $attachments[] = \Illuminate\Mail\Mailables\Attachment::fromPath($archivo['path'])
                ->as($archivo['name'])
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }

        return $attachments;
    }
}
