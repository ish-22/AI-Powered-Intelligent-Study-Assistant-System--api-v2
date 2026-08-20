<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $otp;

    public function __construct(string $otp)
    {
        $this->otp = $otp;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Study Assistant - Your Email Verification OTP',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: "
                <div style='font-family: Arial, sans-serif; padding: 20px; background-color: #090914; color: #ffffff;'>
                    <div style='max-width: 500px; margin: 0 auto; background-color: #0d0d1e; padding: 30px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.1);'>
                        <h2 style='color: #6366f1; margin-top: 0;'>Verify Your Email Address</h2>
                        <p style='color: #a1a1aa; font-size: 14px;'>Use the following 6-digit verification code to complete your Study Assistant registration:</p>
                        <div style='background-color: #1e1b4b; border: 1px solid #6366f1; color: #ffffff; font-size: 28px; font-weight: bold; letter-spacing: 6px; text-align: center; padding: 15px; border-radius: 12px; margin: 25px 0;'>
                            {$this->otp}
                        </div>
                        <p style='color: #71717a; font-size: 12px; margin-bottom: 0;'>This OTP code will expire in 10 minutes. If you did not request this email, please ignore it.</p>
                    </div>
                </div>
            ",
        );
    }
}
