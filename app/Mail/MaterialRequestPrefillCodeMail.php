<?php

namespace App\Mail;

use App\Models\MaterialRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MaterialRequestPrefillCodeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public MaterialRequest $request, public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your material request prefill code');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.material-request-prefill-code',
            with: ['request' => $this->request, 'code' => $this->code],
        );
    }
}
