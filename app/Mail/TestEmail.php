<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TestEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct()
    {
        // Inisialisasi properti jika diperlukan
    }

    public function build()
    {
        return $this->subject('Test Email Subject')
                    ->view('emails.test');
    }
}
