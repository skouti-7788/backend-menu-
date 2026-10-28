<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class HubFlyTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $apiKey
    ) {
        parent::__construct();

        if ($this->apiKey === '') {
            throw new RuntimeException(
                'HUBFLY_MAIL_KEY is not configured.'
            );
        }
    }

    protected function doSend(SentMessage $message): void
    {
        $rawMessage = $message->toString();
        $envelope = $message->getEnvelope();
        $sender = $envelope->getSender()->getAddress();
        $recipients = $envelope->getRecipients();

        if (count($recipients) !== 1) {
            throw new RuntimeException(
                'HubFly transport currently supports one recipient per message.'
            );
        }

        $recipient = $recipients[0]->getAddress();

        $response = Http::withToken($this->apiKey)
            ->withHeaders([
                'X-Envelope-From' => $sender,
                'X-Envelope-To' => $recipient,
                'Idempotency-Key' => hash('sha256', $rawMessage),
            ])
            ->withBody($rawMessage, 'message/rfc822')
            ->timeout(20)
            ->post('https://mail.hubmail.space/v1/messages');

        $response->throw();
    }

    public function __toString(): string
    {
        return 'hubfly';
    }
}
