<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\URL;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Contracts\Queue\ShouldQueue;

class AdminNotification extends Mailable
{
    use Queueable, SerializesModels;

    protected $user;
    protected $transactionId;

    public function __construct($user, $transactionId)
    {
        $this->user = $user;
        $this->transactionId = $transactionId;
    }

    public function build()
    {
        $url = route('admin.approve', ['userId' => $this->user->id, 'transactionId' => $this->transactionId]);

        return $this->subject('Approved Check Product From The Crew')
                    ->view('emails.approved_crew')
                    ->with([
                        'user' => $this->user,
                        'approvalUrl' => $url
                    ]);
    }
    

}
